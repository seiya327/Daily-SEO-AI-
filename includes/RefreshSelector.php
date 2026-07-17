<?php

declare(strict_types=1);

namespace DSAP;

final class RefreshSelector
{
    public function queueCandidates(string $trigger = 'cron'): int
    {
        $settings = Settings::get();
        if (empty($settings['refresh_enabled']) && $trigger === 'cron') {
            return 0;
        }
        if ($trigger === 'cron') {
            $lastSync = get_option('dsap_gsc_last_sync', []);
            if (!is_array($lastSync) || empty($lastSync['synced_at']) || !empty($lastSync['error'])) {
                return 0;
            }
        }
        $limit = max(0, min(5, (int) $settings['max_daily_refreshes']));
        if ($limit === 0) {
            return 0;
        }

        $metrics = new MetricsRepository();
        $candidates = [];
        $postIds = array_values(array_unique(array_merge($metrics->postIds(), $metrics->managedPostIds())));
        foreach ($postIds as $postId) {
            if (!$this->eligibleByCooldown($postId, (int) $settings['refresh_cooldown_days'])) {
                continue;
            }
            $comparison = $metrics->comparison($postId);
            $evaluation = $this->evaluate($postId, $comparison, (int) $settings['refresh_min_impressions']);
            if ($evaluation['eligible']) {
                $comparison['reason'] = $evaluation['reason'];
                $comparison['score'] = $evaluation['score'];
                $comparison['top_queries'] = $metrics->topQueries($postId, $comparison['current_range'][0], $comparison['current_range'][1]);
                $candidates[] = ['post_id' => $postId, 'metrics' => $comparison, 'score' => $evaluation['score']];
            }
        }
        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        $queued = 0;
        $repo = new JobRepository();
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            if ($repo->createRefreshJob((int) $candidate['post_id'], $candidate['metrics'], $trigger) > 0) {
                $queued++;
            }
        }
        return $queued;
    }

    public function queuePost(int $postId, string $trigger = 'manual'): int
    {
        if (get_post_type($postId) !== 'post') {
            return 0;
        }
        $metrics = (new MetricsRepository())->comparison($postId);
        $metrics['reason'] = 'manual_request';
        $metrics['score'] = 1000;
        $metrics['top_queries'] = (new MetricsRepository())->topQueries($postId, $metrics['current_range'][0], $metrics['current_range'][1]);
        return (new JobRepository())->createRefreshJob($postId, $metrics, $trigger);
    }

    private function eligibleByCooldown(int $postId, int $days): bool
    {
        if (get_post_status($postId) !== 'publish' || get_post_meta($postId, '_dsap_refresh_pending_job_id', true)) {
            return false;
        }
        $last = (string) get_post_meta($postId, '_dsap_last_refresh_at', true);
        return $last === '' || strtotime($last) < time() - max(14, $days) * DAY_IN_SECONDS;
    }

    private function evaluate(int $postId, array $comparison, int $minImpressions): array
    {
        $current = $comparison['current'];
        $previous = $comparison['previous'];
        if ((float) $current['impressions'] < $minImpressions) {
            $post = get_post($postId);
            $age = $post instanceof \WP_Post ? time() - strtotime($post->post_date_gmt . ' UTC') : 0;
            if ((float) $current['impressions'] <= 0 && $age >= 45 * DAY_IN_SECONDS) {
                return ['eligible' => true, 'score' => 25, 'reason' => 'no_visibility_after_45_days'];
            }
            return ['eligible' => false, 'score' => 0, 'reason' => 'insufficient_data'];
        }

        $reasons = [];
        $score = 0.0;
        $position = (float) $current['position'];
        $positionDecay = $position - (float) $previous['position'];
        if ((float) $previous['position'] > 0 && $positionDecay >= 1.5) {
            $reasons[] = 'position_decay';
            $score += $positionDecay * 20;
        }
        $ctr = (float) $current['ctr'];
        if ($ctr < 0.02 && (float) $current['impressions'] >= $minImpressions) {
            $reasons[] = 'low_ctr';
            $score += (0.02 - $ctr) * 2000;
        }
        $previousClicks = (float) $previous['clicks'];
        if ($previousClicks >= 5 && (float) $current['clicks'] < $previousClicks * 0.75) {
            $reasons[] = 'click_decay';
            $score += (($previousClicks - (float) $current['clicks']) / $previousClicks) * 50;
        }
        if ($position >= 4 && $position <= 20) {
            $reasons[] = 'ranking_opportunity';
            $score += (21 - $position) * 2;
        }
        $articleType = (string) get_post_meta($postId, '_dsap_article_type', true);
        $eventType = $articleType === 'cv' ? 'affiliate_click' : 'internal_cta_click';
        $ctaClicks = (int) ($comparison['current_cta'][$eventType] ?? 0);
        $pageViews = max((int) ($comparison['current_cta']['page_view'] ?? 0), (int) ($comparison['current_cta']['ga4_page_view'] ?? 0));
        if ($pageViews >= 20 && ($ctaClicks / max(1, $pageViews)) < 0.03) {
            $reasons[] = $articleType === 'cv' ? 'low_affiliate_click_rate' : 'low_internal_cta_rate';
            $score += 35;
        }
        $engagementSeconds = (int) ($comparison['current_cta']['ga4_engagement_seconds'] ?? 0);
        if ($pageViews >= 20 && $engagementSeconds > 0 && ($engagementSeconds / max(1, $pageViews)) < 25) {
            $reasons[] = 'low_engagement_time';
            $score += 30;
        }
        if ($articleType === 'cv' && $pageViews >= 20 && (int) ($comparison['current_cta']['ga4_key_event'] ?? 0) === 0) {
            $reasons[] = 'no_ga4_key_events';
            $score += 20;
        }
        $score += min(100, log10(max(10, (float) $current['impressions'])) * 10);
        return ['eligible' => $reasons !== [], 'score' => (int) round($score), 'reason' => implode(',', array_unique($reasons))];
    }
}
