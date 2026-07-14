<?php

declare(strict_types=1);

namespace DSAP;

final class Database
{
    public const DB_VERSION = '0.4.0';

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'dsap_' . $name;
    }

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $topics = self::table('topics');
        $jobs = self::table('jobs');
        $metrics = self::table('metrics_daily');
        $events = self::table('events_daily');

        dbDelta("CREATE TABLE {$topics} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(191) NOT NULL,
            article_type VARCHAR(32) NOT NULL DEFAULT 'attraction',
            cluster_name VARCHAR(191) NULL,
            content_role VARCHAR(32) NULL,
            reader_stage VARCHAR(32) NULL,
            target_keyword VARCHAR(191) NULL,
            entry_angle TEXT NULL,
            conversion_bridge TEXT NULL,
            target_url TEXT NULL,
            anchor_text VARCHAR(255) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            priority INT NOT NULL DEFAULT 50,
            instructions LONGTEXT NULL,
            last_job_at DATETIME NULL,
            cooldown_until DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status_priority (status, priority),
            KEY keyword (keyword),
            KEY article_type (article_type)
        ) {$charset};");

        dbDelta("CREATE TABLE {$jobs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_key VARCHAR(191) NOT NULL,
            job_type VARCHAR(32) NOT NULL DEFAULT 'new_article',
            topic_id BIGINT UNSIGNED NULL,
            target_post_id BIGINT UNSIGNED NULL,
            post_id BIGINT UNSIGNED NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            stage VARCHAR(32) NOT NULL DEFAULT 'research',
            attempt INT NOT NULL DEFAULT 0,
            lease_token VARCHAR(64) NULL,
            lease_expires_at DATETIME NULL,
            instruction_snapshot LONGTEXT NULL,
            instruction_hash VARCHAR(64) NULL,
            source_post_hash VARCHAR(64) NULL,
            revision_id BIGINT UNSIGNED NULL,
            payload LONGTEXT NULL,
            error_message TEXT NULL,
            usage_json LONGTEXT NULL,
            scheduled_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_key (run_key),
            KEY status_stage (status, stage),
            KEY lease_expires_at (lease_expires_at),
            KEY target_post_id (target_post_id),
            KEY post_id (post_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$metrics} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            metric_date_pt DATE NOT NULL,
            query_text VARCHAR(500) NOT NULL DEFAULT '',
            clicks DOUBLE NOT NULL DEFAULT 0,
            impressions DOUBLE NOT NULL DEFAULT 0,
            ctr DOUBLE NOT NULL DEFAULT 0,
            position DOUBLE NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_date_query (post_id, metric_date_pt, query_text(191)),
            KEY metric_date_pt (metric_date_pt)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            event_date DATE NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_date_type (post_id, event_date, event_type),
            KEY event_date (event_date),
            KEY event_type (event_type)
        ) {$charset};");

        update_option('dsap_db_version', self::DB_VERSION, false);
    }
}
