<?php

declare(strict_types=1);

namespace DSAP;

final class ConversionRepository
{
    public function record(int $postId, string $eventType): void
    {
        global $wpdb;
        if ($postId <= 0 || !in_array($eventType, ['page_view', 'affiliate_click', 'internal_cta_click'], true)) {
            return;
        }
        $table = Database::table('events_daily');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (post_id, event_date, event_type, event_count, created_at, updated_at) VALUES (%d, %s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE event_count = event_count + 1, updated_at = VALUES(updated_at)",
            $postId,
            $date,
            $eventType,
            current_time('mysql'),
            current_time('mysql')
        ));
    }

    public function summary(int $postId, string $start, string $end): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, SUM(event_count) total FROM " . Database::table('events_daily') . " WHERE post_id = %d AND event_date BETWEEN %s AND %s GROUP BY event_type",
            $postId,
            $start,
            $end
        ), ARRAY_A) ?: [];
        $summary = ['page_view' => 0, 'affiliate_click' => 0, 'internal_cta_click' => 0];
        foreach ($rows as $row) {
            $type = (string) ($row['event_type'] ?? '');
            if (array_key_exists($type, $summary)) {
                $summary[$type] = (int) ($row['total'] ?? 0);
            }
        }
        return $summary;
    }
}
