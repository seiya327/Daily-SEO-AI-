<?php

declare(strict_types=1);

namespace DSAP;

final class Scheduler
{
    public const HOOK_DAILY_GENERATE = 'dsap_daily_generate';
    public const HOOK_RETRY_JOB = 'dsap_retry_job';
    public const HOOK_RECOVER_STALE = 'dsap_recover_stale_jobs';
    public const HOOK_GSC_SYNC = 'dsap_gsc_sync';
    public const HOOK_DAILY_REFRESH = 'dsap_daily_refresh';

    public static function boot(): void
    {
        add_action(self::HOOK_DAILY_GENERATE, [self::class, 'dailyGenerate']);
        add_action(self::HOOK_RETRY_JOB, [self::class, 'runJob']);
        add_action(self::HOOK_RECOVER_STALE, [self::class, 'recoverStale']);
        add_action(self::HOOK_GSC_SYNC, [self::class, 'syncSearchConsole']);
        add_action(self::HOOK_DAILY_REFRESH, [self::class, 'queueRefreshCandidates']);
    }

    public static function scheduleEvents(): void
    {
        $settings = Settings::get();
        self::rescheduleDaily($settings);
        self::reschedulePdca($settings);

        if (!wp_next_scheduled(self::HOOK_RECOVER_STALE)) {
            wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK_RECOVER_STALE);
        }
    }

    public static function clearEvents(): void
    {
        foreach ([self::HOOK_DAILY_GENERATE, self::HOOK_RECOVER_STALE, self::HOOK_GSC_SYNC, self::HOOK_DAILY_REFRESH] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            while ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
    }

    public static function rescheduleDaily(array $settings): void
    {
        $timestamp = wp_next_scheduled(self::HOOK_DAILY_GENERATE);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_DAILY_GENERATE);
        }

        if (empty($settings['daily_enabled'])) {
            return;
        }

        wp_schedule_event(self::nextDailyTimestamp((string) $settings['daily_time']), 'daily', self::HOOK_DAILY_GENERATE);
    }

    public static function reschedulePdca(array $settings): void
    {
        foreach ([self::HOOK_GSC_SYNC, self::HOOK_DAILY_REFRESH] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
        if (empty($settings['gsc_enabled']) || !GoogleOAuth::connected()) {
            return;
        }
        $syncAt = self::nextDailyTimestamp((string) $settings['gsc_sync_time']);
        wp_schedule_event($syncAt, 'daily', self::HOOK_GSC_SYNC);
        if (!empty($settings['refresh_enabled'])) {
            wp_schedule_event($syncAt + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY_REFRESH);
        }
    }

    public static function dailyGenerate(): void
    {
        $settings = Settings::get();
        $topicRepo = new TopicRepository();
        $jobRepo = new JobRepository();
        if ($topicRepo->activeCount() === 0) {
            if (!$jobRepo->hasActiveStrategyJob()) {
                $strategyJobId = self::queueStrategyJob('auto_refill');
                if ($strategyJobId > 0) {
                    wp_schedule_single_event(time() + 5, self::HOOK_RETRY_JOB, [$strategyJobId]);
                }
            }
            return;
        }
        $max = max(1, min(10, (int) $settings['max_daily_new_articles']));
        for ($i = 0; $i < $max; $i++) {
            $preferred = ($i === 0 && !self::hasPublishedCv()) ? 'cv' : self::typeForSlot($i, $max, (int) $settings['attraction_ratio']);
            $jobId = self::queueDailyJob('cron', $preferred);
            if ($jobId > 0) {
                wp_schedule_single_event(time() + 5, self::HOOK_RETRY_JOB, [$jobId]);
            }
        }
    }

    public static function queueDailyJob(string $trigger, ?string $preferredType = null): int
    {
        $topicRepo = new TopicRepository();
        if ($preferredType === 'attraction') {
            $topic = $topicRepo->nextReadyAttraction();
            if (!$topic) {
                $topic = $topicRepo->nextActive('cv');
            }
        } elseif ($preferredType === 'cv') {
            $topic = $topicRepo->nextActive('cv');
            if (!$topic) {
                $topic = $topicRepo->nextReadyAttraction();
            }
        } else {
            $topic = $topicRepo->nextReadyAttraction() ?: $topicRepo->nextActive('cv');
        }
        if (!$topic) {
            return 0;
        }

        $jobId = (new JobRepository())->createNewArticleJob($topic, $trigger);
        if ($jobId > 0) {
            $topicRepo->markUsed((int) $topic['id']);
        }

        return $jobId;
    }

    public static function runJob(int $jobId): void
    {
        (new Pipeline(self::client()))->run($jobId);
    }

    public static function recoverStale(): void
    {
        (new JobRepository())->recoverStale();
        self::scheduleQueuedJobs();
    }

    public static function syncSearchConsole(): int|\WP_Error
    {
        $end = new \DateTimeImmutable('-3 days', new \DateTimeZone('America/Los_Angeles'));
        $result = (new SearchConsoleClient())->syncRange($end->modify('-3 days')->format('Y-m-d'), $end->format('Y-m-d'));
        if (is_wp_error($result)) {
            update_option('dsap_gsc_last_sync', ['error' => $result->get_error_message(), 'synced_at' => current_time('mysql')], false);
        }
        return $result;
    }

    public static function queueRefreshCandidates(): int
    {
        $count = (new RefreshSelector())->queueCandidates('cron');
        self::scheduleQueuedRefreshJobs();
        return $count;
    }

    public static function scheduleQueuedRefreshJobs(): void
    {
        self::scheduleQueuedJobs('refresh');
    }

    public static function scheduleQueuedJobs(?string $jobType = null): void
    {
        foreach ((new JobRepository())->latest(100) as $job) {
            $matchesType = $jobType === null || ($job['job_type'] ?? '') === $jobType;
            if ($matchesType && ($job['status'] ?? '') === 'queued' && !wp_next_scheduled(self::HOOK_RETRY_JOB, [(int) $job['id']])) {
                wp_schedule_single_event(time() + 5, self::HOOK_RETRY_JOB, [(int) $job['id']]);
            }
        }
    }

    public static function scheduleNextStage(int $jobId, int $delay = 5): void
    {
        wp_schedule_single_event(time() + max(1, min(300, $delay)), self::HOOK_RETRY_JOB, [$jobId]);
    }

    public static function queueStrategyJob(string $trigger): int
    {
        return (new JobRepository())->createStrategyJob($trigger);
    }

    private static function typeForSlot(int $slot, int $total, int $attractionRatio): string
    {
        $position = (((int) wp_date('z')) * $total + $slot) % 10;
        $attractionSlots = (int) round(max(0, min(100, $attractionRatio)) / 10);
        return $position < $attractionSlots ? 'attraction' : 'cv';
    }

    private static function hasPublishedCv(): bool
    {
        return get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_key' => '_dsap_article_type',
            'meta_value' => 'cv',
            'fields' => 'ids',
            'numberposts' => 1,
        ]) !== [];
    }

    private static function client(): AiClientInterface
    {
        $settings = Settings::get();
        if (!empty($settings['mock_mode']) || Settings::apiKey() === '') {
            return new MockAiClient();
        }

        return new OpenAiClient(Settings::apiKey());
    }

    private static function nextDailyTimestamp(string $time): int
    {
        [$hour, $minute] = array_map('absint', explode(':', $time) + [0, 0]);
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $next = $now->setTime($hour, $minute);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }
}
