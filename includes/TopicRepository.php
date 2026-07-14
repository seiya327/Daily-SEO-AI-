<?php

declare(strict_types=1);

namespace DSAP;

final class TopicRepository
{
    public function create(string $keyword, string $instructions = '', string $articleType = 'attraction', string $cluster = '', string $targetUrl = '', string $anchorText = '', int $priority = 50): int
    {
        global $wpdb;
        $articleType = $articleType === 'cv' ? 'cv' : 'attraction';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . Database::table('topics') . " WHERE keyword = %s AND article_type = %s LIMIT 1",
            $keyword,
            $articleType
        ));
        if ($existing) {
            return (int) $existing;
        }

        $now = current_time('mysql');
        $wpdb->insert(Database::table('topics'), [
            'keyword' => $keyword,
            'article_type' => $articleType,
            'cluster_name' => $cluster,
            'target_url' => $targetUrl,
            'anchor_text' => $anchorText,
            'instructions' => $instructions,
            'status' => 'active',
            'priority' => max(1, min(100, $priority)),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    public function nextActive(?string $preferredType = null): ?array
    {
        global $wpdb;
        $now = current_time('mysql');
        $typeSql = '';
        $args = [$now];
        if (in_array($preferredType, ['attraction', 'cv'], true)) {
            $typeSql = ' AND article_type = %s';
            $args[] = $preferredType;
        }
        $sql = "SELECT * FROM " . Database::table('topics') . " WHERE status = 'active' AND (cooldown_until IS NULL OR cooldown_until <= %s){$typeSql} ORDER BY priority DESC, COALESCE(last_job_at, '1970-01-01') ASC, id ASC LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function markUsed(int $topicId): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->update(Database::table('topics'), [
            'last_job_at' => $now,
            'cooldown_until' => wp_date('Y-m-d H:i:s', time() + DAY_IN_SECONDS, wp_timezone()),
            'updated_at' => $now,
        ], ['id' => $topicId], ['%s', '%s', '%s'], ['%d']);
    }

    public function find(int $topicId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . Database::table('topics') . " WHERE id = %d", $topicId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function latest(int $limit): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . Database::table('topics') . " ORDER BY id DESC LIMIT %d", $limit), ARRAY_A) ?: [];
    }
}
