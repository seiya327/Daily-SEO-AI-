<?php

declare(strict_types=1);

namespace DSAP;

final class TopicRepository
{
    public function existsByKeyword(string $keyword): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM " . Database::table('topics') . " WHERE keyword = %s", $keyword)
        ) > 0;
    }

    public function create(string $keyword, string $instructions = '', string $articleType = 'attraction', string $cluster = '', string $targetUrl = '', string $anchorText = '', int $priority = 50, array $strategyMeta = []): int
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
            'content_role' => sanitize_key((string) ($strategyMeta['content_role'] ?? '')),
            'reader_stage' => sanitize_key((string) ($strategyMeta['reader_stage'] ?? '')),
            'target_keyword' => sanitize_text_field((string) ($strategyMeta['target_keyword'] ?? '')),
            'entry_angle' => sanitize_textarea_field((string) ($strategyMeta['entry_angle'] ?? '')),
            'conversion_bridge' => sanitize_textarea_field((string) ($strategyMeta['conversion_bridge'] ?? '')),
            'target_url' => $targetUrl,
            'anchor_text' => $anchorText,
            'instructions' => $instructions,
            'status' => 'active',
            'priority' => max(1, min(100, $priority)),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);

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

    public function nextReadyAttraction(): ?array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . Database::table('topics') . " WHERE status = 'active' AND article_type = 'attraction' AND (cooldown_until IS NULL OR cooldown_until <= %s) ORDER BY priority DESC, COALESCE(last_job_at, '1970-01-01') ASC, id ASC LIMIT 100",
            current_time('mysql')
        ), ARRAY_A) ?: [];
        $cvPostIds = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_key' => '_dsap_article_type',
            'meta_value' => 'cv',
            'fields' => 'ids',
            'numberposts' => 500,
        ]);
        $clusters = [];
        $keywords = [];
        foreach ($cvPostIds as $postId) {
            $cluster = (string) get_post_meta((int) $postId, '_dsap_cluster_name', true);
            $keyword = (string) get_post_meta((int) $postId, '_dsap_focus_keyword', true);
            if ($cluster !== '') {
                $clusters[$cluster] = true;
            }
            if ($keyword !== '') {
                $keywords[$keyword] = true;
            }
        }
        foreach ($rows as $row) {
            $cluster = (string) ($row['cluster_name'] ?? '');
            $targetKeyword = (string) ($row['target_keyword'] ?? '');
            if (($targetKeyword !== '' && isset($keywords[$targetKeyword])) || ($cluster !== '' && isset($clusters[$cluster]))) {
                return $row;
            }
        }
        return null;
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

    public function markCompleted(int $topicId): void
    {
        global $wpdb;
        $wpdb->update(Database::table('topics'), [
            'status' => 'completed',
            'cooldown_until' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $topicId], ['%s', '%s', '%s'], ['%d']);
    }

    public function markRejected(int $topicId): void
    {
        global $wpdb;
        $wpdb->update(Database::table('topics'), [
            'status' => 'rejected',
            'cooldown_until' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $topicId], ['%s', '%s', '%s'], ['%d']);
    }

    public function activeCount(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Database::table('topics') . " WHERE status = 'active'");
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
