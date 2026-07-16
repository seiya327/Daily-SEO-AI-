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

        $cta = $this->ctaData((int) $postId, $funnel, $article);
        if ($cta['target'] === '') {
            $decision['post_status'] = 'draft';
            update_post_meta((int) $postId, '_dsap_needs_review_reason', 'CV導線のリンク先を確定できません。');
        }
        $body = wp_kses_post((string) ($article['content_html'] ?? ''));
        $content = $this->visualLead($article, $research, $funnel);
        $content .= $body;
        $content .= $this->visualChart($article, $research, $payload);
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

    private function visualLead(array $article, array $research, array $funnel): string
    {
        $keyword = sanitize_text_field((string) ($article['focus_keyword'] ?? $research['primary_keyword'] ?? $funnel['target_keyword'] ?? ''));
        $excerpt = sanitize_text_field((string) ($article['excerpt'] ?? ''));
        $type = ($funnel['article_type'] ?? 'attraction') === 'cv' ? 'CV記事' : '集客記事';
        $role = sanitize_text_field((string) ($funnel['content_role'] ?? ''));
        $sources = is_array($research['sources'] ?? null) ? count($research['sources']) : 0;
        $facts = is_array($research['facts'] ?? null) ? count($research['facts']) : 0;
        $angle = sanitize_text_field((string) ($funnel['entry_angle'] ?? ''));

        $badges = [
            '<span>' . esc_html($type) . '</span>',
            $role !== '' ? '<span>' . esc_html($role) . '</span>' : '',
            $sources > 0 ? '<span>参照元 ' . esc_html((string) $sources) . '件</span>' : '',
        ];
        $badges = implode('', array_filter($badges));

        $items = [];
        if ($keyword !== '') {
            $items[] = '<li><strong>狙う検索意図</strong><span>' . esc_html($keyword) . '</span></li>';
        }
        if ($angle !== '') {
            $items[] = '<li><strong>入口</strong><span>' . esc_html($angle) . '</span></li>';
        }
        if ($facts > 0) {
            $items[] = '<li><strong>根拠</strong><span>調査ファクト ' . esc_html((string) $facts) . '件を反映</span></li>';
        }

        return '<section class="dsap-visual-lead"><div class="dsap-visual-copy"><div class="dsap-badges">' . $badges . '</div><p class="dsap-visual-summary">' . esc_html($excerpt) . '</p><ul class="dsap-keypoints">' . implode('', $items) . '</ul></div><figure class="dsap-visual-figure"><div class="dsap-figure-mark"><span></span><span></span><span></span></div><figcaption>この記事の判断ポイント</figcaption></figure></section>';
    }

    private function visualChart(array $article, array $research, array $payload): string
    {
        $html = (string) ($article['content_html'] ?? '');
        preg_match_all('/<h2\b[^>]*>/i', $html, $h2Matches);
        $sourceCount = is_array($research['sources'] ?? null) ? count($research['sources']) : 0;
        $linkCount = is_array($article['internal_link_post_ids'] ?? null) ? count($article['internal_link_post_ids']) : 0;
        $decision = is_array($payload['publish_decision'] ?? null) ? $payload['publish_decision'] : [];
        $score = max(0, min(100, (int) ($decision['score'] ?? 0)));
        $h2Count = count($h2Matches[0] ?? []);

        $rows = [
            ['label' => '根拠の量', 'value' => $sourceCount . ' source', 'class' => $this->levelClass($sourceCount, 3, 6)],
            ['label' => '構成の深さ', 'value' => $h2Count . ' section', 'class' => $this->levelClass($h2Count, 4, 7)],
            ['label' => '内部導線', 'value' => $linkCount . ' link', 'class' => $this->levelClass($linkCount, 1, 3)],
            ['label' => '品質判定', 'value' => $score > 0 ? $score . '/100' : 'review', 'class' => $this->levelClass($score, 75, 88)],
        ];

        $htmlRows = '';
        foreach ($rows as $row) {
            $htmlRows .= '<div class="dsap-meter-row"><div><strong>' . esc_html($row['label']) . '</strong><span>' . esc_html($row['value']) . '</span></div><div class="dsap-meter ' . esc_attr($row['class']) . '"><span></span></div></div>';
        }

        return '<section class="dsap-visual-chart"><h2>判断材料の整理</h2><p>本文を読む前に、根拠・構成・導線・品質判定を確認できます。</p>' . $htmlRows . '</section>';
    }

    private function levelClass(int $value, int $mid, int $high): string
    {
        if ($value >= $high) {
            return 'is-high';
        }
        if ($value >= $mid) {
            return 'is-mid';
        }
        return 'is-low';
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
