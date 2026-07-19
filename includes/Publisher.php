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
            $decision['post_status'] = 'draft';
            $decision['draft_reasons'][] = 'CTA target is missing';
            update_post_meta((int) $postId, '_dsap_needs_review_reason', 'CV導線のリンク先を確定できません。');
        }
        $body = wp_kses_post((string) ($article['content_html'] ?? ''));
        $content = $this->addIllustration($body, $article, $funnel);
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
        return (int) $postId;
    }

    private function ctaData(int $postId, array $funnel, array $article): array
    {
        $settings = Settings::get();
        $type = ($funnel['article_type'] ?? 'attraction') === 'cv' ? 'cv' : 'attraction';
        $target = '';
        $eventType = $type === 'cv' ? 'affiliate_click' : 'internal_cta_click';
        if ($type === 'cv') {
            $target = esc_url_raw((string) ($funnel['target_url'] ?? ''));
            $target = $target !== '' ? $target : esc_url_raw((string) $settings['affiliate_url']);
        } else {
            $target = $this->findCvPostUrl((string) ($funnel['target_keyword'] ?? ''), (string) ($funnel['cluster_name'] ?? ''));
        }
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
        $rel = $type === 'cv' ? ' rel="sponsored nofollow"' : '';
        $disclosure = $type === 'cv' ? '<p class="dsap-disclosure"><small>' . esc_html((string) $settings['affiliate_disclosure']) . '</small></p>' : '';
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

    private function addIllustration(string $html, array $article, array $funnel): string
    {
        $figure = $this->illustration((string) ($article['title'] ?? ''), (string) ($funnel['article_type'] ?? 'attraction'));
        if ($figure === '') {
            return $html;
        }

        $inserted = preg_replace_callback('/(<\/p>)/i', static fn (array $matches): string => (string) $matches[1] . $figure, $html, 1);
        return is_string($inserted) && $inserted !== $html ? $inserted : $figure . $html;
    }

    private function illustration(string $title, string $articleType): string
    {
        $label = sanitize_text_field($title) !== '' ? sanitize_text_field($title) : 'Article illustration';
        $accent = $articleType === 'cv' ? '#d95d39' : '#1f8a70';
        $accentAlt = $articleType === 'cv' ? '#f2b84b' : '#70c1b3';
        $svg = '<svg class="dsap-illustration-svg" viewBox="0 0 960 360" role="img" aria-label="' . esc_attr($label) . '" xmlns="http://www.w3.org/2000/svg">'
            . '<rect width="960" height="360" rx="0" fill="#f7f9fb"/>'
            . '<path d="M0 262 C154 196 277 304 430 238 C560 181 662 154 960 226 L960 360 L0 360 Z" fill="#e7f3ef"/>'
            . '<path d="M0 294 C183 246 296 327 462 275 C620 226 752 250 960 198 L960 360 L0 360 Z" fill="#f7eedf"/>'
            . '<circle cx="778" cy="96" r="54" fill="' . esc_attr($accentAlt) . '"/>'
            . '<rect x="112" y="82" width="238" height="168" rx="18" fill="#ffffff" stroke="#d8e1ea" stroke-width="4"/>'
            . '<rect x="142" y="116" width="130" height="14" rx="7" fill="' . esc_attr($accent) . '"/>'
            . '<rect x="142" y="154" width="168" height="12" rx="6" fill="#b9c7d3"/>'
            . '<rect x="142" y="184" width="122" height="12" rx="6" fill="#d8e1ea"/>'
            . '<rect x="420" y="122" width="118" height="174" rx="16" fill="' . esc_attr($accent) . '"/>'
            . '<rect x="562" y="76" width="118" height="220" rx="16" fill="#17324d"/>'
            . '<rect x="704" y="158" width="118" height="138" rx="16" fill="' . esc_attr($accentAlt) . '"/>'
            . '<path d="M170 286 L798 286" stroke="#17324d" stroke-width="8" stroke-linecap="round"/>'
            . '<circle cx="214" cy="286" r="18" fill="#ffffff" stroke="#17324d" stroke-width="6"/>'
            . '<circle cx="812" cy="286" r="18" fill="#ffffff" stroke="#17324d" stroke-width="6"/>'
            . '</svg>';

        return '<figure class="dsap-article-illustration">' . $svg . '</figure>';
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
