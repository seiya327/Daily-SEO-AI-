<?php

declare(strict_types=1);

namespace DSAP;

final class Publisher
{
    public function publish(int $jobId, array $payload): int|\WP_Error
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $research = is_array($payload['research'] ?? null) ? $payload['research'] : [];
        $funnel = is_array($payload['funnel'] ?? null) ? $payload['funnel'] : [];
        if ($article === []) {
            return new \WP_Error('dsap_missing_article', 'Missing article payload.');
        }

        $placeholderSlug = 'dsap-job-' . $jobId;
        $postId = $this->recoverPost($jobId, $placeholderSlug);
        $decision = is_array($payload['publish_decision'] ?? null) ? $payload['publish_decision'] : ['post_status' => (string) Settings::get()['post_status']];
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
            update_post_meta((int) $postId, '_dsap_slug_adjusted_from', $finalSlug);
        }

        $cta = $this->ctaData((int) $postId, $funnel, $article);
        $articleType = ($funnel['article_type'] ?? 'attraction') === 'cv' ? 'cv' : 'attraction';
        if ($cta['target'] === '' && $articleType === 'cv') {
            $decision['publish_warnings'] = is_array($decision['publish_warnings'] ?? null) ? $decision['publish_warnings'] : [];
            $decision['publish_warnings'][] = 'CV先URLが未設定のため、この記事はCTAなしで公開しました。';
        }
        $body = wp_kses_post((string) ($article['content_html'] ?? ''));
        $content = ArticleVisuals::enhance(
            $body,
            (string) ($article['title'] ?? ''),
            (string) ($funnel['article_type'] ?? 'attraction'),
            (string) ($article['answer_summary'] ?? '')
        );
        $content .= $this->relatedLinks($article);
        $content .= $this->references($article, $research);
        $content .= $cta['html'];

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
        update_post_meta((int) $postId, '_dsap_content_role', sanitize_key((string) ($funnel['content_role'] ?? '')));
        update_post_meta((int) $postId, '_dsap_reader_stage', sanitize_key((string) ($funnel['reader_stage'] ?? '')));
        update_post_meta((int) $postId, '_dsap_target_keyword', sanitize_text_field((string) ($funnel['target_keyword'] ?? '')));
        update_post_meta((int) $postId, '_dsap_meta_description', sanitize_text_field((string) ($article['meta_description'] ?? '')));
        update_post_meta((int) $postId, '_dsap_focus_keyword', sanitize_text_field((string) ($article['focus_keyword'] ?? '')));
        update_post_meta((int) $postId, '_dsap_answer_summary', sanitize_textarea_field((string) ($article['answer_summary'] ?? '')));
        update_post_meta((int) $postId, '_dsap_cta_target', $cta['target']);
        update_post_meta((int) $postId, '_dsap_cta_event_type', $cta['event_type']);
        update_post_meta((int) $postId, '_dsap_cta_lead', sanitize_text_field((string) ($article['cta_lead'] ?? '')));
        update_post_meta((int) $postId, '_dsap_cta_anchor', sanitize_text_field((string) ($article['cta_anchor'] ?? '')));
        update_post_meta((int) $postId, '_dsap_payload_hash', hash('sha256', wp_json_encode($payload) ?: ''));
        update_post_meta((int) $postId, '_dsap_publish_decision', wp_json_encode($decision));
        $warnings = is_array($decision['publish_warnings'] ?? null) ? array_map('strval', $decision['publish_warnings']) : [];
        if ((string) $decision['post_status'] === 'draft') {
            $reasons = is_array($decision['draft_reasons'] ?? null) ? array_map('strval', $decision['draft_reasons']) : [];
            if ($reasons !== []) {
                update_post_meta((int) $postId, '_dsap_needs_review_reason', implode(' / ', array_slice($reasons, 0, 5)));
            }
        } else {
            delete_post_meta((int) $postId, '_dsap_needs_review_reason');
            if ($warnings !== []) {
                update_post_meta((int) $postId, '_dsap_publish_warnings', implode(' / ', array_slice($warnings, 0, 5)));
            } else {
                delete_post_meta((int) $postId, '_dsap_publish_warnings');
            }
        }
        if ((string) $decision['post_status'] === 'publish') {
            ArticleImageGenerator::schedule((int) $postId);
        }
        return (int) $postId;
    }

    private function ctaData(int $postId, array $funnel, array $article): array
    {
        $settings = Settings::get();
        $type = ($funnel['article_type'] ?? 'attraction') === 'cv' ? 'cv' : 'attraction';
        $target = '';
        if ($type === 'cv') {
            $target = esc_url_raw((string) ($funnel['target_url'] ?? ''));
            $target = $target !== '' ? $target : esc_url_raw((string) $settings['affiliate_url']);
            $target = $target !== '' ? $target : $this->findConversionPageUrl();
        } else {
            $target = $this->findCvPostUrl((string) ($funnel['target_keyword'] ?? ''), (string) ($funnel['cluster_name'] ?? ''));
        }
        $targetHost = strtolower((string) wp_parse_url($target, PHP_URL_HOST));
        $siteHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $isExternalCv = $type === 'cv' && $targetHost !== '' && $siteHost !== '' && $targetHost !== $siteHost;
        $eventType = $isExternalCv ? 'affiliate_click' : 'internal_cta_click';
        if ($target === '') {
            return ['html' => '', 'target' => '', 'event_type' => $eventType];
        }

        $lead = sanitize_text_field((string) ($article['cta_lead'] ?? ''));
        $anchor = sanitize_text_field((string) ($article['cta_anchor'] ?? ''));
        if ($anchor === '') {
            $anchor = $type === 'cv' ? sanitize_text_field((string) $settings['affiliate_anchor']) : sanitize_text_field((string) ($funnel['anchor_text'] ?? ''));
        }
        if ($anchor === '') {
            $anchor = $type === 'cv' ? '公式サイトで詳細を確認する' : '比較・選び方の記事へ進む';
        }
        $trackingUrl = add_query_arg(['dsap_go' => $postId], home_url('/'));
        $rel = $isExternalCv ? ' rel="sponsored nofollow"' : '';
        $disclosure = $isExternalCv ? '<p class="dsap-disclosure"><small>' . esc_html((string) $settings['affiliate_disclosure']) . '</small></p>' : '';
        $leadHtml = $lead !== '' ? '<p class="dsap-cta-lead">' . esc_html($lead) . '</p>' : '';
        $html = '<aside class="dsap-cta dsap-cta-' . esc_attr($type) . '">' . $disclosure . $leadHtml . '<p><a href="' . esc_url($trackingUrl) . '"' . $rel . '>' . esc_html($anchor) . '</a></p></aside>';
        return ['html' => $html, 'target' => $target, 'event_type' => $eventType];
    }

    private function relatedLinks(array $article): string
    {
        $items = [];
        foreach ((array) ($article['internal_link_post_ids'] ?? []) as $postId) {
            $postId = absint($postId);
            if ($postId <= 0 || get_post_status($postId) !== 'publish') {
                continue;
            }
            $url = get_permalink($postId);
            $title = get_the_title($postId);
            if ($url && $title !== '') {
                $items[] = '<li><a href="' . esc_url((string) $url) . '">' . esc_html((string) $title) . '</a></li>';
            }
        }
        if ($items === []) {
            return '';
        }
        return '<aside class="dsap-related"><h2>あわせて読みたい</h2><ul>' . implode('', array_unique($items)) . '</ul></aside>';
    }

    private function references(array $article, array $research): string
    {
        $sources = is_array($research['sources'] ?? null) ? $research['sources'] : [];
        $items = [];
        foreach (array_values(array_unique(array_map('intval', (array) ($article['source_indexes'] ?? [])))) as $index) {
            if (!isset($sources[$index]) || !is_array($sources[$index])) {
                continue;
            }
            $url = esc_url_raw((string) ($sources[$index]['url'] ?? ''));
            $title = sanitize_text_field((string) ($sources[$index]['title'] ?? $url));
            $publisher = sanitize_text_field((string) ($sources[$index]['publisher'] ?? ''));
            if ($url !== '') {
                $label = $publisher !== '' ? $title . ' - ' . $publisher : $title;
                $items[] = '<li><a href="' . esc_url($url) . '" rel="noopener noreferrer" target="_blank">' . esc_html($label) . '</a></li>';
            }
        }
        if ($items === []) {
            return '';
        }
        return '<section class="dsap-references"><h2>参考資料</h2><ul>' . implode('', $items) . '</ul></section>';
    }

    private function findCvPostUrl(string $targetKeyword, string $cluster): string
    {
        if ($targetKeyword !== '') {
            $posts = get_posts([
                'post_type' => 'post',
                'post_status' => 'publish',
                'fields' => 'ids',
                'numberposts' => 1,
                'meta_query' => [
                    ['key' => '_dsap_article_type', 'value' => 'cv'],
                    ['key' => '_dsap_focus_keyword', 'value' => $targetKeyword],
                ],
            ]);
            if (!empty($posts[0])) {
                return (string) get_permalink((int) $posts[0]);
            }
        }
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_query' => [
                ['key' => '_dsap_article_type', 'value' => 'cv'],
            ],
        ];
        if ($cluster !== '') {
            $args['meta_query'][] = ['key' => '_dsap_cluster_name', 'value' => $cluster];
        }
        $posts = get_posts($args);
        return !empty($posts[0]) ? (string) get_permalink((int) $posts[0]) : '';
    }

    private function findConversionPageUrl(): string
    {
        foreach (['contact', 'contact-us', 'inquiry', 'apply', 'entry', 'reservation', 'booking', 'consultation', 'document-request'] as $slug) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page instanceof \WP_Post && $page->post_status === 'publish') {
                return (string) get_permalink($page->ID);
            }
        }
        return '';
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
