<?php
/**
 * Plugin Name: Daily SEO AI Publisher
 * Description: Researches, drafts, audits, and prepares SEO articles with OpenAI from a WordPress admin workflow.
 * Version: 0.5.9
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Update URI: https://github.com/seiya327/Daily-SEO-AI-
 * Author: Codex
 * Text Domain: daily-seo-ai-publisher
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DSAP_VERSION', '0.5.9');
define('DSAP_FILE', __FILE__);
define('DSAP_DIR', plugin_dir_path(__FILE__));
define('DSAP_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'DSAP\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = DSAP_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, ['DSAP\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['DSAP\\Activator', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    DSAP\Plugin::instance()->boot();
});
