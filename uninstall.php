<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('dsap_settings', []);
if (!is_array($settings) || empty($settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dsap_metrics_daily");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dsap_events_daily");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dsap_jobs");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dsap_topics");

delete_option('dsap_settings');
delete_option('dsap_db_version');
delete_option('dsap_strategy_plan');
delete_option('dsap_auto_setup_status');
delete_option('dsap_gsc_tokens');
delete_option('dsap_gsc_last_sync');
delete_option('dsap_gsc_sites');
