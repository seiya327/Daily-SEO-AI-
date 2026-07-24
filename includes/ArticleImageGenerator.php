<?php

declare(strict_types=1);

namespace DSAP;

final class ArticleImageGenerator
{
    public static function schedule(int $postId): void
    {
        $settings = Settings::get();
        $provider = (string) ($settings['article_image_provider'] ?? 'openverse');
        if ($postId <= 0 || $provider === 'none') {
            return;
        }
        $existingId = (int) get_post_meta($postId, '_dsap_generated_image_id', true);
        if ($existingId > 0) {
            if (wp_attachment_is_image($existingId)) {
                return;
            }
            delete_post_meta($postId, '_dsap_generated_image_id');
        }
        if (!wp_next_scheduled(Scheduler::HOOK_GENERATE_IMAGE, [$postId])) {
            wp_schedule_single_event(time() + 10, Scheduler::HOOK_GENERATE_IMAGE, [$postId]);
        }
    }

    public function generate(int $postId): void
    {
        $post = get_post($postId);
        $provider = (string) (Settings::get()['article_image_provider'] ?? 'openverse');
        if (!$post instanceof \WP_Post || !in_array($post->post_status, ['publish', 'draft', 'pending'], true) || $provider === 'none') {
            return;
        }
        $existingId = (int) get_post_meta($postId, '_dsap_generated_image_id', true);
        if ($existingId > 0) {
            if (wp_attachment_is_image($existingId)) {
                return;
            }
            delete_post_meta($postId, '_dsap_generated_image_id');
        }

        $attempts = max(0, (int) get_post_meta($postId, '_dsap_image_attempts', true));
        if ($attempts >= 3) {
            return;
        }
        update_post_meta($postId, '_dsap_image_attempts', $attempts + 1);

        $result = $this->requestOpenverse($postId, $post);
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

    private function requestOpenverse(int $postId, \WP_Post $post): array|\WP_Error
    {
        $query = sanitize_text_field((string) get_post_meta($postId, '_dsap_image_search_query', true));
        if ($query === '') {
            $query = sanitize_text_field((string) get_post_meta($postId, '_dsap_focus_keyword', true));
        }
        if ($query === '') {
            return new \WP_Error('dsap_image_query_missing', '挿絵の検索語がありません。');
        }
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $queries = [$query];
        if (count($words) > 3) {
            $queries[] = implode(' ', array_slice($words, 0, 3));
        }
        if (count($words) > 2) {
            $queries[] = implode(' ', array_slice($words, 0, 2));
        }
        $results = [];
        foreach (array_unique($queries) as $searchQuery) {
            $endpoint = add_query_arg([
                'q' => $searchQuery,
                'license_type' => 'commercial',
                'page_size' => 10,
                'mature' => 'false',
            ], 'https://api.openverse.org/v1/images/');
            $response = wp_remote_get($endpoint, [
                'timeout' => 12,
                'headers' => ['User-Agent' => 'Daily-SEO-AI-Publisher/' . DSAP_VERSION . '; ' . home_url('/')],
            ]);
            if (is_wp_error($response)) {
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $json = json_decode((string) wp_remote_retrieve_body($response), true);
            if ($code === 429) {
                return new \WP_Error('dsap_image_api', 'Openverseの利用上限に達しました。時間を置いて再試行します。', ['status' => $code]);
            }
            if ($code >= 200 && $code < 300 && is_array($json['results'] ?? null)) {
                $results = $json['results'];
            }
            if ($results !== []) {
                break;
            }
        }
        $downloadAttempts = 0;
        foreach ($results as $candidate) {
            if (!is_array($candidate) || !empty($candidate['mature']) || !in_array((string) ($candidate['license'] ?? ''), ['cc0', 'pdm', 'by', 'by-sa'], true)) {
                continue;
            }
            $width = (int) ($candidate['width'] ?? 0);
            $height = (int) ($candidate['height'] ?? 0);
            if ($width > 0 && $height > 0 && ($width / $height < 1.2 || $width / $height > 2.4)) {
                continue;
            }
            foreach (array_unique([(string) ($candidate['url'] ?? ''), (string) ($candidate['thumbnail'] ?? '')]) as $imageUrl) {
                if ($downloadAttempts >= 2) {
                    break 2;
                }
                $downloadAttempts++;
                $download = $this->downloadOpenverseImage($imageUrl);
                if (is_wp_error($download)) {
                    continue;
                }
                return array_merge($download, [
                    'alt' => sanitize_text_field((string) get_post_meta($postId, '_dsap_image_alt', true)) ?: sanitize_text_field($post->post_title . 'の挿絵'),
                    'provider' => 'openverse',
                    'title' => sanitize_text_field((string) ($candidate['title'] ?? '')),
                    'creator' => sanitize_text_field((string) ($candidate['creator'] ?? '')),
                    'creator_url' => esc_url_raw((string) ($candidate['creator_url'] ?? '')),
                    'license' => strtoupper(sanitize_key((string) ($candidate['license'] ?? ''))),
                    'license_url' => esc_url_raw((string) ($candidate['license_url'] ?? '')),
                    'source_url' => esc_url_raw((string) ($candidate['foreign_landing_url'] ?? '')),
                ]);
            }
        }
        return new \WP_Error('dsap_image_empty', '記事内容に合う商用利用可能な挿絵をOpenverseで確認できませんでした。');
    }

    private function downloadOpenverseImage(string $url): array|\WP_Error
    {
        $url = esc_url_raw($url);
        if ($url === '' || !in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return new \WP_Error('dsap_image_url', '挿絵URLが不正です。');
        }
        $response = wp_safe_remote_get($url, ['timeout' => 18, 'redirection' => 4, 'limit_response_size' => 12 * MB_IN_BYTES]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $bytes = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $bytes === '' || strlen($bytes) > 12 * MB_IN_BYTES) {
            return new \WP_Error('dsap_image_download', '挿絵ファイルを取得できませんでした。');
        }
        if (!function_exists('getimagesizefromstring')) {
            return new \WP_Error('dsap_image_validation', '画像形式を検証できません。');
        }
        $info = getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return new \WP_Error('dsap_image_invalid', '対応していない挿絵形式です。');
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 480 || $height < 270 || $width / max(1, $height) < 1.2 || $width / max(1, $height) > 2.4) {
            return new \WP_Error('dsap_image_small', '挿絵の解像度が不足しています。');
        }
        return ['bytes' => $bytes, 'mime' => $mime];
    }

    private function store(int $postId, \WP_Post $post, array $result): int|\WP_Error
    {
        $base = sanitize_title((string) get_post_meta($postId, '_dsap_focus_keyword', true));
        $mime = (string) ($result['mime'] ?? 'image/webp');
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $extension = $extensions[$mime] ?? 'webp';
        $filename = ($base !== '' ? $base : 'dsap-article-' . $postId) . '.' . $extension;
        $upload = wp_upload_bits($filename, null, (string) ($result['bytes'] ?? ''));
        if (!empty($upload['error']) || empty($upload['file']) || empty($upload['url'])) {
            return new \WP_Error('dsap_image_upload', (string) ($upload['error'] ?? '生成画像をメディアライブラリへ保存できませんでした。'));
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $mime,
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
        $alt = sanitize_text_field((string) ($result['alt'] ?? '')) ?: sanitize_text_field($post->post_title . 'の挿絵');
        update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', $alt);
        update_post_meta((int) $attachmentId, '_dsap_image_provider', sanitize_key((string) ($result['provider'] ?? '')));
        foreach (['title', 'creator', 'creator_url', 'license', 'license_url', 'source_url'] as $field) {
            $value = str_ends_with($field, '_url') ? esc_url_raw((string) ($result[$field] ?? '')) : sanitize_text_field((string) ($result[$field] ?? ''));
            if ($value !== '') {
                update_post_meta((int) $attachmentId, '_dsap_image_' . $field, $value);
            }
        }
        return (int) $attachmentId;
    }

    public static function figure(int $attachmentId): string
    {
        $image = wp_get_attachment_image($attachmentId, 'large', false, [
            'class' => 'dsap-generated-image-media',
            'loading' => 'lazy',
            'decoding' => 'async',
        ]);
        if (!is_string($image) || $image === '') {
            return '';
        }
        $captionParts = [];
        $title = sanitize_text_field((string) get_post_meta($attachmentId, '_dsap_image_title', true));
        $sourceUrl = esc_url((string) get_post_meta($attachmentId, '_dsap_image_source_url', true));
        if ($title !== '') {
            $captionParts[] = $sourceUrl !== '' ? '<a href="' . $sourceUrl . '" rel="noopener">' . esc_html($title) . '</a>' : esc_html($title);
        }
        $creator = sanitize_text_field((string) get_post_meta($attachmentId, '_dsap_image_creator', true));
        $creatorUrl = esc_url((string) get_post_meta($attachmentId, '_dsap_image_creator_url', true));
        if ($creator !== '') {
            $captionParts[] = $creatorUrl !== '' ? '<a href="' . $creatorUrl . '" rel="noopener">' . esc_html($creator) . '</a>' : esc_html($creator);
        }
        $license = sanitize_text_field((string) get_post_meta($attachmentId, '_dsap_image_license', true));
        $licenseUrl = esc_url((string) get_post_meta($attachmentId, '_dsap_image_license_url', true));
        if ($license !== '') {
            $captionParts[] = $licenseUrl !== '' ? '<a href="' . $licenseUrl . '" rel="license noopener">' . esc_html($license) . '</a>' : esc_html($license);
        }
        $caption = $captionParts !== [] ? '<figcaption>画像: ' . implode(' / ', $captionParts) . '</figcaption>' : '';
        return '<figure class="dsap-generated-image">' . $image . $caption . '</figure>';
    }

    private function insertIntoContent(\WP_Post $post, int $attachmentId): void
    {
        $current = get_post($post->ID);
        if (!$current instanceof \WP_Post || str_contains($current->post_content, 'dsap-generated-image')) {
            return;
        }
        $figure = self::figure($attachmentId);
        if ($figure === '') {
            return;
        }
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
