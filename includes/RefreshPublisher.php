<?php

declare(strict_types=1);

namespace DSAP;

final class RefreshPublisher
{
    public function apply(array $job, array $payload): int|\WP_Error
    {
        $postId = (int) ($job['target_post_id'] ?? 0);
        $post = get_post($postId);
        $article = is_array($payload['refresh_article'] ?? null) ? $payload['refresh_article'] : [];
        if (!$post instanceof \WP_Post || $article === []) {
            return new \WP_Error('dsap_refresh_missing', '改善対象の記事または改善原稿がありません。');
        }

        $currentHash = JobRepository::postHash($post);
        $sourceChanged = !hash_equals((string) ($job['source_post_hash'] ?? ''), $currentHash);
        $safe = $this->passesAudit($payload);
        $autoApply = !empty(Settings::get()['refresh_auto_apply']) && $safe && !$sourceChanged;

        if (!$autoApply) {
            $draftId = $this->createDraft($post, $article, $job, $sourceChanged, $safe);
            if (!is_wp_error($draftId)) {
                update_post_meta($postId, '_dsap_refresh_pending_job_id', (int) $job['id']);
            }
            return $draftId;
        }

        $revisionId = wp_save_post_revision($postId);
        if (is_wp_error($revisionId)) {
            return $revisionId;
        }
        if (is_int($revisionId) && $revisionId > 0) {
            (new JobRepository())->setRevision((int) $job['id'], $revisionId);
        }

        $content = $this->composeContent($post->post_content, $article);
        $updated = wp_update_post([
            'ID' => $postId,
            'post_title' => sanitize_text_field((string) ($article['title'] ?? $post->post_title)),
            'post_excerpt' => sanitize_text_field((string) ($article['excerpt'] ?? $post->post_excerpt)),
            'post_content' => $content,
        ], true);
        if (is_wp_error($updated)) {
            return $updated;
        }
        update_post_meta($postId, '_dsap_meta_description', sanitize_text_field((string) ($article['meta_description'] ?? '')));
        update_post_meta($postId, '_dsap_focus_keyword', sanitize_text_field((string) ($article['focus_keyword'] ?? '')));
        update_post_meta($postId, '_dsap_cta_lead', sanitize_text_field((string) ($article['cta_lead'] ?? '')));
        update_post_meta($postId, '_dsap_cta_anchor', sanitize_text_field((string) ($article['cta_anchor'] ?? '')));
        update_post_meta($postId, '_dsap_last_refresh_at', current_time('mysql'));
        update_post_meta($postId, '_dsap_last_refresh_job_id', (int) $job['id']);
        delete_post_meta($postId, '_dsap_refresh_pending_job_id');
        return $postId;
    }

    public function applyDraft(int $jobId): int|\WP_Error
    {
        $job = (new JobRepository())->find($jobId);
        if (!$job || ($job['job_type'] ?? '') !== 'refresh') {
            return new \WP_Error('dsap_refresh_job_missing', '改善ジョブが見つかりません。');
        }
        $targetId = (int) ($job['target_post_id'] ?? 0);
        $draftId = (int) ($job['post_id'] ?? 0);
        $target = get_post($targetId);
        $draft = get_post($draftId);
        if (!$target instanceof \WP_Post || !$draft instanceof \WP_Post || get_post_status($draftId) !== 'draft') {
            return new \WP_Error('dsap_refresh_draft_missing', '適用できる改善案の下書きがありません。');
        }
        $revisionId = wp_save_post_revision($targetId);
        if (is_wp_error($revisionId)) {
            return $revisionId;
        }
        if (is_int($revisionId) && $revisionId > 0) {
            (new JobRepository())->setRevision($jobId, $revisionId);
        }
        $title = preg_replace('/^【改善案】/u', '', $draft->post_title) ?: $target->post_title;
        $updated = wp_update_post([
            'ID' => $targetId,
            'post_title' => $title,
            'post_excerpt' => $draft->post_excerpt,
            'post_content' => $draft->post_content,
        ], true);
        if (is_wp_error($updated)) {
            return $updated;
        }
        foreach (['_dsap_meta_description', '_dsap_focus_keyword', '_dsap_cta_lead', '_dsap_cta_anchor'] as $key) {
            update_post_meta($targetId, $key, (string) get_post_meta($draftId, $key, true));
        }
        update_post_meta($targetId, '_dsap_last_refresh_at', current_time('mysql'));
        update_post_meta($targetId, '_dsap_last_refresh_job_id', $jobId);
        delete_post_meta($targetId, '_dsap_refresh_pending_job_id');
        wp_trash_post($draftId);
        return $targetId;
    }

    public function discardDraft(int $jobId): true|\WP_Error
    {
        $job = (new JobRepository())->find($jobId);
        if (!$job || ($job['job_type'] ?? '') !== 'refresh') {
            return new \WP_Error('dsap_refresh_job_missing', '改善ジョブが見つかりません。');
        }
        $targetId = (int) ($job['target_post_id'] ?? 0);
        $draftId = (int) ($job['post_id'] ?? 0);
        if ($draftId > 0 && get_post_status($draftId) === 'draft') {
            wp_trash_post($draftId);
        }
        delete_post_meta($targetId, '_dsap_refresh_pending_job_id');
        return true;
    }

    private function createDraft(\WP_Post $post, array $article, array $job, bool $sourceChanged, bool $safe): int|\WP_Error
    {
        $content = $this->composeContent($post->post_content, $article);
        $reason = $sourceChanged ? 'original_changed' : ($safe ? 'manual_review' : 'audit_failed');
        return wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => '【改善案】' . sanitize_text_field((string) ($article['title'] ?? $post->post_title)),
            'post_excerpt' => sanitize_text_field((string) ($article['excerpt'] ?? $post->post_excerpt)),
            'post_content' => $content,
            'post_category' => wp_get_post_categories($post->ID),
            'tags_input' => wp_get_post_tags($post->ID, ['fields' => 'names']),
            'meta_input' => [
                '_dsap_refresh_target_post_id' => $post->ID,
                '_dsap_refresh_job_id' => (int) $job['id'],
                '_dsap_refresh_review_reason' => $reason,
                '_dsap_meta_description' => sanitize_text_field((string) ($article['meta_description'] ?? '')),
                '_dsap_focus_keyword' => sanitize_text_field((string) ($article['focus_keyword'] ?? '')),
                '_dsap_cta_lead' => sanitize_text_field((string) ($article['cta_lead'] ?? '')),
                '_dsap_cta_anchor' => sanitize_text_field((string) ($article['cta_anchor'] ?? '')),
            ],
        ], true);
    }

    private function passesAudit(array $payload): bool
    {
        $audit = is_array($payload['audit'] ?? null) ? $payload['audit'] : [];
        $components = [];
        foreach (['intent_coverage', 'factual_support', 'clarity', 'originality', 'seo_quality', 'information_gain', 'conversion_quality', 'reader_trust', 'internal_link_quality'] as $name) {
            $components[] = max(0, min(100, (int) ($audit[$name] ?? 0)));
        }
        $componentScore = $components === [] ? 0 : (int) round(array_sum($components) / count($components));
        $score = min((int) ($audit['overall_score'] ?? 0), $componentScore);
        $minimum = (int) (Settings::qualityProfile()['audit_score'] ?? 85);
        return $score >= $minimum
            && empty($audit['ymyl'])
            && empty($audit['critical_issues'])
            && empty($audit['unsupported_claims']);
    }

    private function composeContent(string $original, array $article): string
    {
        $proposed = wp_kses_post((string) ($article['content_html'] ?? ''));
        foreach (['dsap-related', 'dsap-references'] as $className) {
            $pattern = '/<(aside|section)\s+class=["\'][^"\']*\b' . preg_quote($className, '/') . '\b[^"\']*["\'][^>]*>.*?<\/\1>/is';
            if (preg_match($pattern, $original, $section)) {
                $proposed = rtrim($proposed) . "\n" . $section[0];
            }
        }
        if (preg_match('/<aside\s+class=["\'][^"\']*\bdsap-cta\b[^"\']*["\'][^>]*>.*?<\/aside>/is', $original, $match)) {
            $cta = $this->refreshCtaCopy($match[0], $article);
            $proposed = rtrim($proposed) . "\n" . $cta;
        }
        return $proposed;
    }

    private function refreshCtaCopy(string $cta, array $article): string
    {
        $lead = sanitize_text_field((string) ($article['cta_lead'] ?? ''));
        $anchor = sanitize_text_field((string) ($article['cta_anchor'] ?? ''));
        if ($lead !== '') {
            $leadHtml = '<p class="dsap-cta-lead">' . esc_html($lead) . '</p>';
            if (preg_match('/<p\s+class=["\'][^"\']*\bdsap-cta-lead\b[^"\']*["\'][^>]*>.*?<\/p>/is', $cta)) {
                $cta = preg_replace('/<p\s+class=["\'][^"\']*\bdsap-cta-lead\b[^"\']*["\'][^>]*>.*?<\/p>/is', $leadHtml, $cta, 1) ?: $cta;
            } else {
                $cta = preg_replace('/(<p\s+class=["\'][^"\']*\bdsap-disclosure\b[^"\']*["\'][^>]*>.*?<\/p>)/is', '$1' . $leadHtml, $cta, 1, $count) ?: $cta;
                if (empty($count)) {
                    $cta = preg_replace('/(<aside\b[^>]*>)/i', '$1' . $leadHtml, $cta, 1) ?: $cta;
                }
            }
        }
        if ($anchor !== '') {
            $cta = preg_replace_callback('/(<a\b[^>]*>).*?(<\/a>)/is', static fn(array $match): string => $match[1] . esc_html($anchor) . $match[2], $cta, 1) ?: $cta;
        }
        return $cta;
    }
}
