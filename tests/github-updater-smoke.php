<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

defined('DSAP_VERSION') || define('DSAP_VERSION', 'test');
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);

$GLOBALS['dsap_test_transients'] = [];
$GLOBALS['dsap_http_calls'] = [];
$GLOBALS['dsap_zip_bytes'] = "PK\x03\x04public release asset";
$GLOBALS['dsap_zip_hash'] = hash('sha256', $GLOBALS['dsap_zip_bytes']);

function get_transient(string $key): mixed
{
    return $GLOBALS['dsap_test_transients'][$key] ?? false;
}

function set_transient(string $key, mixed $value, int $expiration): bool
{
    unset($expiration);
    $GLOBALS['dsap_test_transients'][$key] = $value;
    return true;
}

function esc_url_raw(mixed $value): string
{
    return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : '';
}

function wp_tempnam(string $filename = ''): string|false
{
    unset($filename);
    return tempnam(sys_get_temp_dir(), 'dsap-update-');
}

function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    $GLOBALS['dsap_http_calls'][] = ['url' => $url, 'args' => $args];
    if (str_contains($url, '/releases/assets/')) {
        throw new RuntimeException('Authenticated GitHub asset API URL was used for a public release.');
    }
    if (str_ends_with($url, '/releases/latest')) {
        return ['response' => ['code' => 200], 'body' => wp_json_encode([
            'tag_name' => 'v9.9.9',
            'body' => 'test',
            'published_at' => '2026-07-21T00:00:00Z',
            'assets' => [
                [
                    'name' => 'daily-seo-ai-publisher.zip',
                    'url' => 'https://api.github.com/repos/seiya327/Daily-SEO-AI-/releases/assets/1',
                    'browser_download_url' => 'https://github.com/seiya327/Daily-SEO-AI-/releases/download/v9.9.9/daily-seo-ai-publisher.zip',
                ],
                [
                    'name' => 'daily-seo-ai-publisher.zip.sha256',
                    'url' => 'https://api.github.com/repos/seiya327/Daily-SEO-AI-/releases/assets/2',
                    'browser_download_url' => 'https://github.com/seiya327/Daily-SEO-AI-/releases/download/v9.9.9/daily-seo-ai-publisher.zip.sha256',
                ],
            ],
        ])];
    }
    $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];
    if (isset($headers['Authorization'])) {
        throw new RuntimeException('Authorization header leaked to a public release download URL.');
    }
    if (str_ends_with($url, '.zip.sha256')) {
        return ['response' => ['code' => 200], 'body' => $GLOBALS['dsap_zip_hash'] . "  daily-seo-ai-publisher.zip\n"];
    }
    if (str_ends_with($url, '.zip')) {
        if (!empty($args['stream']) && !empty($args['filename'])) {
            file_put_contents((string) $args['filename'], $GLOBALS['dsap_zip_bytes']);
        }
        return ['response' => ['code' => 200], 'body' => ''];
    }
    return new WP_Error('unexpected_url', $url);
}

function wp_remote_retrieve_response_code(array|WP_Error $response): int
{
    return is_wp_error($response) ? 0 : (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body(array|WP_Error $response): string
{
    return is_wp_error($response) ? '' : (string) ($response['body'] ?? '');
}

function wp_remote_retrieve_header(array|WP_Error $response, string $header): string
{
    unset($response, $header);
    return '';
}

require dirname(__DIR__) . '/includes/Settings.php';
require dirname(__DIR__) . '/includes/GitHubUpdater.php';

use DSAP\GitHubUpdater;
use DSAP\Settings;

$GLOBALS['dsap_test_options'][Settings::OPTION] = array_merge(Settings::defaults(), [
    'github_token' => 'legacy-token-that-must-not-reach-public-assets',
]);

$updater = new GitHubUpdater();
$release = $updater->release();
if (is_wp_error($release)) {
    throw new RuntimeException($release->get_error_message());
}
$expectedUrl = 'https://github.com/seiya327/Daily-SEO-AI-/releases/download/v9.9.9/daily-seo-ai-publisher.zip';
if (($release['package'] ?? '') !== $expectedUrl || ($release['sha256'] ?? '') !== $GLOBALS['dsap_zip_hash']) {
    throw new RuntimeException('Public release URL or checksum was not selected correctly.');
}

$download = $updater->preDownload(false, $expectedUrl, null, []);
if (is_wp_error($download) || !is_string($download) || hash_file('sha256', $download) !== $GLOBALS['dsap_zip_hash']) {
    throw new RuntimeException('Downloaded public release did not pass checksum validation.');
}
@unlink($download);

echo "public_asset=browser_download_url authorization=none checksum=match\n";
