<?php

declare(strict_types=1);

namespace DSAP;

final class JobRepository
{
    public function createNewArticleJob(array $topic, string $trigger): int
    {
        global $wpdb;

        $settings = Settings::get();
        $snapshot = [
            'global' => $settings['global_instructions'],
            'topic' => $topic['instructions'] ?? '',
            'trigger' => $trigger,
            'article_type' => $topic['article_type'] ?? 'attraction',
            'cluster_name' => $topic['cluster_name'] ?? '',
            'content_role' => $topic['content_role'] ?? '',
            'reader_stage' => $topic['reader_stage'] ?? '',
            'target_keyword' => $topic['target_keyword'] ?? '',
            'entry_angle' => $topic['entry_angle'] ?? '',
            'conversion_bridge' => $topic['conversion_bridge'] ?? '',
            'target_url' => $topic['target_url'] ?? '',
            'anchor_text' => $topic['anchor_text'] ?? '',
            'conversion_goal' => $settings['conversion_goal'],
            'affiliate_url' => $settings['affiliate_url'],
            'affiliate_anchor' => $settings['affiliate_anchor'],
        ];
        $snapshotJson = wp_json_encode($snapshot);
        $runKey = 'new:' . (string) $topic['id'] . ':' . gmdate('Ymd') . ':' . wp_generate_uuid4();
        $now = current_time('mysql');

        $ok = $wpdb->insert(Database::table('jobs'), [
            'run_key' => $runKey,
            'job_type' => 'new_article',
            'topic_id' => (int) $topic['id'],
            'status' => 'queued',
            'stage' => 'research',
            'instruction_snapshot' => $snapshotJson,
            'instruction_hash' => hash('sha256', (string) $snapshotJson),
            'scheduled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        if ($ok === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function createStrategyJob(string $trigger): int
    {
        global $wpdb;
        $settings = Settings::get();
        $snapshot = [
            'trigger' => $trigger,
            'site_theme' => $settings['site_theme'],
            'target_audience' => $settings['target_audience'],
            'conversion_goal' => $settings['conversion_goal'],
            'affiliate_url' => $settings['affiliate_url'],
            'attraction_ratio' => $settings['attraction_ratio'],
            'keyword_strategy' => $settings['keyword_strategy'],
            'strategy_instructions' => $settings['strategy_instructions'],
            'global' => $settings['global_instructions'],
        ];
        $snapshotJson = wp_json_encode($snapshot);
        $now = current_time('mysql');
        $ok = $wpdb->insert(Database::table('jobs'), [
            'run_key' => 'strategy:' . gmdate('YmdHis') . ':' . wp_generate_uuid4(),
            'job_type' => 'site_strategy',
            'status' => 'queued',
            'stage' => 'strategy',
            'instruction_snapshot' => $snapshotJson,
            'instruction_hash' => hash('sha256', (string) $snapshotJson),
            'scheduled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    public function hasActiveStrategyJob(): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . Database::table('jobs') . " WHERE job_type = 'site_strategy' AND status IN ('queued', 'running', 'failed_retryable')"
        ) > 0;
    }

    public function createRefreshJob(int $postId, array $metrics, string $trigger): int
    {
        global $wpdb;
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return 0;
        }
        $settings = Settings::get();
        $snapshot = [
            'global' => $settings['global_instructions'],
            'refresh' => $settings['refresh_instructions'],
            'trigger' => $trigger,
            'target_post_id' => $postId,
        ];
        $snapshotJson = wp_json_encode($snapshot);
        $sourceHash = self::postHash($post);
        $now = current_time('mysql');
        $ok = $wpdb->insert(Database::table('jobs'), [
            'run_key' => 'refresh:' . $postId . ':' . gmdate('o-W'),
            'job_type' => 'refresh',
            'target_post_id' => $postId,
            'status' => 'queued',
            'stage' => 'refresh_plan',
            'instruction_snapshot' => $snapshotJson,
            'instruction_hash' => hash('sha256', (string) $snapshotJson),
            'source_post_hash' => $sourceHash,
            'payload' => wp_json_encode(['metrics' => $metrics]),
            'scheduled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        if ($ok === false) {
            return 0;
        }
        $jobId = (int) $wpdb->insert_id;
        update_post_meta($postId, '_dsap_refresh_pending_job_id', $jobId);
        return $jobId;
    }

    public function setRevision(int $jobId, int $revisionId): void
    {
        global $wpdb;
        $wpdb->update(Database::table('jobs'), [
            'revision_id' => $revisionId,
            'updated_at' => current_time('mysql'),
        ], ['id' => $jobId], ['%d', '%s'], ['%d']);
    }

    public static function postHash(\WP_Post $post): string
    {
        return hash('sha256', $post->post_title . "\n" . $post->post_excerpt . "\n" . $post->post_content);
    }

    public function find(int $jobId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . Database::table('jobs') . " WHERE id = %d", $jobId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function acquire(int $jobId): ?array
    {
        global $wpdb;

        $job = $this->find($jobId);
        if (!$job || in_array($job['status'], ['complete', 'failed_permanent'], true)) {
            return null;
        }

        $token = wp_generate_password(32, false, false);
        $now = current_time('mysql');
        $lease = wp_date('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS, wp_timezone());
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . Database::table('jobs') . " SET status = 'running', lease_token = %s, lease_expires_at = %s, started_at = COALESCE(started_at, %s), updated_at = %s WHERE id = %d AND status NOT IN ('complete', 'failed_permanent') AND (status <> 'running' OR lease_expires_at IS NULL OR lease_expires_at < %s)",
            $token,
            $lease,
            $now,
            $now,
            $jobId,
            $now
        ));

        if ($updated !== 1) {
            return null;
        }

        $job = $this->find($jobId);
        return $job ?: null;
    }

    public function savePayload(int $jobId, array $payload): void
    {
        global $wpdb;

        $wpdb->update(Database::table('jobs'), [
            'payload' => wp_json_encode($payload),
            'updated_at' => current_time('mysql'),
        ], ['id' => $jobId], ['%s', '%s'], ['%d']);
    }

    public function advance(int $jobId, string $nextStage): void
    {
        global $wpdb;

        $wpdb->update(Database::table('jobs'), [
            'status' => 'queued',
            'stage' => $nextStage,
            'attempt' => 0,
            'lease_token' => null,
            'lease_expires_at' => null,
            'error_message' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $jobId], ['%s', '%s', '%d', '%s', '%s', '%s', '%s'], ['%d']);
    }

    public function retry(int $jobId): bool
    {
        global $wpdb;

        $job = $this->find($jobId);
        if (!$job || in_array((string) ($job['status'] ?? ''), ['complete', 'running'], true)) {
            return false;
        }

        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        if (($job['job_type'] ?? '') === 'site_strategy' && ($job['status'] ?? '') === 'failed_permanent') {
            unset(
                $payload['strategy'],
                $payload['strategy_diagnostics'],
                $payload['strategy_sources'],
                $payload['strategy_generation_attempts'],
                $payload['strategy_generation']
            );
            if (is_array($payload['usage'] ?? null)) {
                unset($payload['usage']['strategy'], $payload['usage']['strategy_repair']);
            }
        }

        $wpdb->update(Database::table('jobs'), [
            'status' => 'queued',
            'attempt' => 0,
            'lease_token' => null,
            'lease_expires_at' => null,
            'error_message' => null,
            'payload' => wp_json_encode($payload),
            'updated_at' => current_time('mysql'),
        ], ['id' => $jobId], ['%s', '%d', '%s', '%s', '%s', '%s', '%s'], ['%d']);

        return true;
    }

    public function complete(int $jobId, int $postId): void
    {
        global $wpdb;

        $now = current_time('mysql');
        $wpdb->update(Database::table('jobs'), [
            'status' => 'complete',
            'stage' => 'complete',
            'post_id' => $postId,
            'lease_token' => null,
            'lease_expires_at' => null,
            'error_message' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $jobId], ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'], ['%d']);
    }

    public function fail(int $jobId, string $message, bool $permanent = false): void
    {
        global $wpdb;

        $attempt = $this->attempt($jobId) + 1;
        $finalPermanent = $permanent || $attempt >= 4;

        $wpdb->update(Database::table('jobs'), [
            'status' => $finalPermanent ? 'failed_permanent' : 'failed_retryable',
            'error_message' => substr($message, 0, 1000),
            'attempt' => $attempt,
            'lease_token' => null,
            'lease_expires_at' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $jobId], ['%s', '%s', '%d', '%s', '%s', '%s'], ['%d']);
        $job = $this->find($jobId);
        if ($finalPermanent && !empty($job['target_post_id'])) {
            delete_post_meta((int) $job['target_post_id'], '_dsap_refresh_pending_job_id');
        }
        if (!$finalPermanent) {
            $args = [$jobId];
            if (!wp_next_scheduled(Scheduler::HOOK_RETRY_JOB, $args)) {
                $delay = min(HOUR_IN_SECONDS, 60 * (2 ** max(0, $attempt - 1)));
                wp_schedule_single_event(time() + $delay, Scheduler::HOOK_RETRY_JOB, $args);
            }
        }
    }

    public function recoverStale(): int
    {
        global $wpdb;

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . Database::table('jobs') . " SET status = 'queued', lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s",
                current_time('mysql'),
                current_time('mysql')
            )
        );
    }

    public function latest(int $limit): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM " . Database::table('jobs') . " ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        ) ?: [];
    }

    private function attempt(int $jobId): int
    {
        $job = $this->find($jobId);
        return $job ? (int) $job['attempt'] : 0;
    }
}
