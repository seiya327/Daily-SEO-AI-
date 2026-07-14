<?php

declare(strict_types=1);

namespace DSAP;

final class Publisher
{
    public function publish(int $jobId, array $payload): int|\WP_Error
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $funnel = is_array($payload['funnel'] ?? null) ? $payload['funnel'] : [];
        if ($article === []) {
            return new \WP_Error('dsap_missing_article', 'Missing article payload.');
        }

        $placeholderSlug = 'dsap-job-' . $jobId;
        $postId = $this->recoverPost($jobId, $placeholderSlug);
        $decision = is_array($payload['publish_decision'] ?? null) ? $payload['publish_decision'] : ['post_status' => 'draft'];

        if ($postId === 0) {
            $postId = wp_insert_post([
                'post_title' => sanitize_text_field((string) ($article['title'] ?? $placeholderSlug)),
                'post_name' => $placeholderSlug,
                'post_status' => 'draft',
                'post_type' => 'post',
                'meta_input' => ['_dsap_job_id' => $jobId],
            ], true);
            if (is_wp_error($postId)) {
                return $postId;
            }
        }

        $categoryId = $this->category((string) ($article['category_name'] ?? 'SEO記事'));
        $finalSlug = sanitize_title((string) ($article['slug'] ?? $placeholderSlug)) ?: $placeholderSlug;
        $uniqueSlug = wp_unique_post_slug($finalSlug, (int) $postId, (string) $decision['post_status'], 'post', 0);
        if ($uniqueSlug !== $finalSlug) {
            $decision['post_status'] = 'draft';
            update_post_meta((int) $postId, '_dsap_needs_review_reason', 'Slug collision detected.');
        }

        $content = wp_kses_post((string) ($article['content_html'] ?? ''));
        $content .= $this->cta($funnel);
        $updated = wp_update_post([
            'ID' => (int) $postId,
            'post_title' => sanitize_text_field((string) ($article['title'] ?? '')),
            'post_name' => $uniqueSlug,
            'post_status' => (string) $decision['post_status'],
            'post_type' => 'post',
            'post_content' => $content,
            'post_excerpt' => sanitize_text_field((string) ($article['excerpt'] ?? '')),
            'post_category' => $categoryId > 0 ? [$categoryId] : [],
            'tags_input' => array_map('sanitize_text_field', is_array($article['tags'] ?? null) ? $article['tags'] : []),
        ], true);
        if (is_wp_error($updated)) {
            return $updated;
        }

        update_post_meta((int) $postId, '_dsap_job_id', $jobId);
        update_post_meta((int) $postId, '_dsap_article_type', sanitize_key((string) ($funnel['article_type'] ?? 'attraction')));
        update_post_meta((int) $postId, '_dsap_cluster_name', sanitize_text_field((string) ($funnel['cluster_name'] ?? '')));
        update_post_meta((int) $postId, '_dsap_meta_description', sanitize_text_field((string) ($article['meta_description'] ?? '')));
        update_post_meta((int) $postId, '_dsap_focus_keyword', sanitize_text_field((string) ($article['focus_keyword'] ?? '')));
        update_post_meta((int) $postId, '_dsap_payload_hash', hash('sha256', wp_json_encode($payload) ?: ''));
        update_post_meta((int) $postId, '_dsap_publish_decision', wp_json_encode($decision));
        return (int) $postId;
    }

    private function cta(array $funnel): string
    {
        $settings = Settings::get();
        $type = ($funnel['article_type'] ?? 'attraction') === 'cv' ? 'cv' : 'attraction';
        $url = esc_url_raw((string) ($funnel['target_url'] ?? ''));
        $anchor = sanitize_text_field((string) ($funnel['anchor_text'] ?? ''));

        if ($type === 'cv') {
            $url = $url !== '' ? $url : esc_url_raw((string) $settings['affiliate_url']);
            $anchor = $anchor !== '' ? $anchor : sanitize_text_field((string) $settings['affiliate_anchor']);
        } elseif ($url === '') {
            $url = $this->latestCvPostUrl((string) ($funnel['cluster_name'] ?? ''));
            $anchor = $anchor !== '' ? $anchor : 'おすすめサービスの比較記事を見る';
        }

        if ($url === '') {
            return '';
        }
        $disclosure = $type === 'cv' ? '<p class="dsap-disclosure"><small>' . esc_html((string) $settings['affiliate_disclosure']) . '</small></p>' : '';
        $rel = $type === 'cv' ? ' rel="sponsored nofollow"' : '';
        return '<aside class="dsap-cta">' . $disclosure . '<p><a href="' . esc_url($url) . '"' . $rel . '>' . esc_html($anchor !== '' ? $anchor : '詳しく見る') . '</a></p></aside>';
    }

    private function latestCvPostUrl(string $cluster): string
    {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_key' => '_dsap_article_type',
            'meta_value' => 'cv',
            'fields' => 'ids',
            'numberposts' => 1,
        ];
        if ($cluster !== '') {
            $args['meta_query'] = [
                'relation' => 'AND',
                ['key' => '_dsap_article_type', 'value' => 'cv'],
                ['key' => '_dsap_cluster_name', 'value' => $cluster],
            ];
            unset($args['meta_key'], $args['meta_value']);
        }
        $posts = get_posts($args);
        return !empty($posts[0]) ? (string) get_permalink((int) $posts[0]) : '';
    }

    private function recoverPost(int $jobId, string $placeholderSlug): int
    {
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_dsap_job_id', 'meta_value' => (string) $jobId, 'fields' => 'ids', 'numberposts' => 1]);
        if (!empty($posts[0])) {
            return (int) $posts[0];
        }
        $post = get_page_by_path($placeholderSlug, OBJECT, 'post');
        return $post ? (int) $post->ID : 0;
    }

    private function category(string $name): int
    {
        $name = $name !== '' ? $name : 'SEO記事';
        $term = term_exists($name, 'category');
        if (is_array($term) && isset($term['term_id'])) {
            return (int) $term['term_id'];
        }
        if (is_int($term)) {
            return $term;
        }
        $created = wp_insert_term($name, 'category');
        return is_wp_error($created) || !is_array($created) ? 0 : (int) $created['term_id'];
    }
}
