<?php

declare(strict_types=1);

namespace DSAP;

final class ArticleImageGenerator
{
    public static function schedule(int $postId): void
    {
        $settings = Settings::get();
        if ($postId <= 0 || empty($settings['ai_images_enabled']) || Settings::apiKey() === '') {
            return;
        }
        if (get_post_meta($postId, '_dsap_generated_image_id', true) !== '') {
            return;
        }
        if (!wp_next_scheduled(Scheduler::HOOK_GENERATE_IMAGE, [$postId])) {
            wp_schedule_single_event(time() + 10, Scheduler::HOOK_GENERATE_IMAGE, [$postId]);
        }
    }

    public function generate(int $postId): void
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || empty(Settings::get()['ai_images_enabled'])) {
            return;
        }
        if ((int) get_post_meta($postId, '_dsap_generated_image_id', true) > 0) {
            return;
        }

        $attempts = max(0, (int) get_post_meta($postId, '_dsap_image_attempts', true));
        if ($attempts >= 3) {
            return;
        }
        update_post_meta($postId, '_dsap_image_attempts', $attempts + 1);

        $result = $this->request($postId, $post);
        if (is_wp_error($result)) {
            update_post_meta($postId, '_dsap_image_error', $result->get_error_message());
            $errorData = $result->get_error_data();
            $status = is_array($errorData) ? (int) ($errorData['status'] ?? 0) : 0;
            if ($attempts < 2 && ($status === 0 || $status === 429 || $status >= 500)) {
                wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, Scheduler::HOOK_GENERATE_IMAGE, [$postId]);
            }
            return;
        }

        $attachmentId = $this->store($postId, $post, $result);
        if (is_wp_error($attachmentId)) {
            update_post_meta($postId, '_dsap_image_error', $attachmentId->get_error_message());
            if ($attempts < 2) {
                wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, Scheduler::HOOK_GENERATE_IMAGE, [$postId]);
            }
            return;
        }

        set_post_thumbnail($postId, $attachmentId);
        update_post_meta($postId, '_dsap_generated_image_id', $attachmentId);
        delete_post_meta($postId, '_dsap_image_error');
        $this->insertIntoContent($post, $attachmentId);
    }

    private function request(int $postId, \WP_Post $post): array|\WP_Error
    {
        $keyword = sanitize_text_field((string) get_post_meta($postId, '_dsap_focus_keyword', true));
        $summary = sanitize_textarea_field((string) get_post_meta($postId, '_dsap_answer_summary', true));
        $prompt = 'Create one high-quality editorial illustration for a Japanese web article. '
            . 'The image must directly explain the subject, use a clean realistic editorial style, balanced natural colors, and a clear focal point. '
            . 'Do not include letters, captions, logos, watermarks, UI screenshots, charts with labels, or misleading product details. '
            . 'Landscape composition, suitable for an article at 1536x1024. '
            . 'Article title: ' . sanitize_text_field($post->post_title)
            . '. Focus topic: ' . $keyword
            . '. Article summary: ' . $summary;

        $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
            'timeout' => 150,
            'headers' => [
                'Authorization' => 'Bearer ' . Settings::apiKey(),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => 'gpt-image-2',
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1536x1024',
                'quality' => 'low',
                'output_format' => 'webp',
                'output_compression' => 82,
            ]),
        ]);
        if (is_wp_error($response)) {
            return new \WP_Error('dsap_image_network', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && !empty($json['error']['message']) ? (string) $json['error']['message'] : 'OpenAI画像APIの生成に失敗しました。';
            return new \WP_Error('dsap_image_api', $message, ['status' => $code]);
        }
        $encoded = is_array($json) ? (string) ($json['data'][0]['b64_json'] ?? '') : '';
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '') {
            return new \WP_Error('dsap_image_empty', 'OpenAI画像APIから画像データが返りませんでした。');
        }
        if (strlen($bytes) > 15 * MB_IN_BYTES) {
            return new \WP_Error('dsap_image_too_large', '生成画像が15MBを超えたため保存を中止しました。');
        }
        if (function_exists('getimagesizefromstring')) {
            $imageInfo = getimagesizefromstring($bytes);
            if (!is_array($imageInfo) || (string) ($imageInfo['mime'] ?? '') !== 'image/webp') {
                return new \WP_Error('dsap_image_invalid', 'OpenAI画像APIのデータ形式を確認できませんでした。');
            }
        }
        return ['bytes' => $bytes];
    }

    private function store(int $postId, \WP_Post $post, array $result): int|\WP_Error
    {
        $base = sanitize_title((string) get_post_meta($postId, '_dsap_focus_keyword', true));
        $filename = ($base !== '' ? $base : 'dsap-article-' . $postId) . '.webp';
        $upload = wp_upload_bits($filename, null, (string) ($result['bytes'] ?? ''));
        if (!empty($upload['error']) || empty($upload['file']) || empty($upload['url'])) {
            return new \WP_Error('dsap_image_upload', (string) ($upload['error'] ?? '生成画像をメディアライブラリへ保存できませんでした。'));
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => 'image/webp',
            'post_title' => sanitize_text_field($post->post_title),
            'post_content' => '',
            'post_status' => 'inherit',
        ], (string) $upload['file'], $postId, true);
        if (is_wp_error($attachmentId)) {
            wp_delete_file((string) $upload['file']);
            return $attachmentId;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata((int) $attachmentId, (string) $upload['file']);
        if (is_array($metadata)) {
            wp_update_attachment_metadata((int) $attachmentId, $metadata);
        }
        update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', sanitize_text_field($post->post_title . 'の解説イメージ'));
        return (int) $attachmentId;
    }

    private function insertIntoContent(\WP_Post $post, int $attachmentId): void
    {
        $current = get_post($post->ID);
        if (!$current instanceof \WP_Post || str_contains($current->post_content, 'dsap-generated-image')) {
            return;
        }
        $image = wp_get_attachment_image($attachmentId, 'large', false, [
            'class' => 'dsap-generated-image-media',
            'loading' => 'lazy',
            'decoding' => 'async',
        ]);
        if (!is_string($image) || $image === '') {
            return;
        }
        $figure = '<figure class="dsap-generated-image">' . $image . '</figure>';
        $paragraph = 0;
        $content = preg_replace_callback('/<\/p>/i', static function (array $matches) use (&$paragraph, $figure): string {
            $paragraph++;
            return (string) $matches[0] . ($paragraph === 3 ? $figure : '');
        }, $current->post_content);
        if (!is_string($content) || $content === $current->post_content) {
            $content = $figure . $current->post_content;
        }
        wp_update_post(['ID' => $post->ID, 'post_content' => $content]);
    }
}
