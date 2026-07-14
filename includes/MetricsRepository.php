<?php

declare(strict_types=1);

namespace DSAP;

final class MetricsRepository
{
    public function replaceDate(string $date, array $rows, bool $updateSyncStatus = true): int
    {
        global $wpdb;
        $prepared = [];
        foreach ($rows as $row) {
            $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
            $pageUrl = isset($keys[0]) ? (string) $keys[0] : '';
            $query = isset($keys[1]) ? (string) $keys[1] : '';
            $postId = $pageUrl !== '' ? url_to_postid($pageUrl) : 0;
            if ($postId <= 0 || get_post_type($postId) !== 'post') {
                continue;
            }
            $prepared[] = [
                'post_id' => $postId,
                'query_text' => substr($query, 0, 500),
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        $wpdb->delete(Database::table('metrics_daily'), ['metric_date_pt' => $date], ['%s']);
        $saved = 0;
        foreach ($prepared as $row) {
            $ok = $wpdb->replace(Database::table('metrics_daily'), [
                'post_id' => $row['post_id'],
                'metric_date_pt' => $date,
                'query_text' => $row['query_text'],
                'clicks' => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr' => $row['ctr'],
                'position' => $row['position'],
                'created_at' => current_time('mysql'),
            ], ['%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s']);
            if ($ok !== false) {
                $saved++;
            }
        }
        if ($updateSyncStatus) {
            update_option('dsap_gsc_last_sync', ['date' => $date, 'rows' => $saved, 'synced_at' => current_time('mysql')], false);
        }
        return $saved;
    }

    public function comparison(int $postId, ?\DateTimeImmutable $end = null): array
    {
        $tz = new \DateTimeZone('America/Los_Angeles');
        $end = $end ?: (new \DateTimeImmutable('now', $tz))->modify('-3 days');
        $currentStart = $end->modify('-27 days');
        $previousEnd = $currentStart->modify('-1 day');
        $previousStart = $previousEnd->modify('-27 days');
        return [
            'current' => $this->summary($postId, $currentStart->format('Y-m-d'), $end->format('Y-m-d')),
            'previous' => $this->summary($postId, $previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')),
            'current_range' => [$currentStart->format('Y-m-d'), $end->format('Y-m-d')],
            'previous_range' => [$previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')],
        ];
    }

    public function postIds(int $limit = 500): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM " . Database::table('metrics_daily') . " GROUP BY post_id ORDER BY SUM(impressions) DESC LIMIT %d",
            $limit
        ));
        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function topQueries(int $postId, string $start, string $end, int $limit = 20): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT query_text, SUM(clicks) clicks, SUM(impressions) impressions, SUM(clicks) / NULLIF(SUM(impressions), 0) ctr, SUM(position * impressions) / NULLIF(SUM(impressions), 0) position FROM " . Database::table('metrics_daily') . " WHERE post_id = %d AND metric_date_pt BETWEEN %s AND %s GROUP BY query_text ORDER BY impressions DESC LIMIT %d",
            $postId,
            $start,
            $end,
            $limit
        ), ARRAY_A) ?: [];
    }

    private function summary(int $postId, string $start, string $end): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(clicks), 0) clicks, COALESCE(SUM(impressions), 0) impressions, COALESCE(SUM(clicks) / NULLIF(SUM(impressions), 0), 0) ctr, COALESCE(SUM(position * impressions) / NULLIF(SUM(impressions), 0), 0) position FROM " . Database::table('metrics_daily') . " WHERE post_id = %d AND metric_date_pt BETWEEN %s AND %s",
            $postId,
            $start,
            $end
        ), ARRAY_A);
        return [
            'clicks' => (float) ($row['clicks'] ?? 0),
            'impressions' => (float) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
        ];
    }
}
