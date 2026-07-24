<?php

declare(strict_types=1);

define('DSAP_VERSION', 'test');

require __DIR__ . '/bootstrap.php';

$GLOBALS['dsap_nvidia_posts'] = 0;
$GLOBALS['dsap_nvidia_gets'] = 0;

function esc_url_raw(mixed $value): string
{
    return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : '';
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function home_url(string $path = ''): string
{
    return 'https://site.example' . $path;
}

function wp_remote_post(string $url, array $args): array|WP_Error
{
    if ($url !== 'https://integrate.api.nvidia.com/v1/chat/completions') {
        return new WP_Error('unexpected_url', $url);
    }
    $GLOBALS['dsap_nvidia_posts']++;
    $request = json_decode((string) ($args['body'] ?? ''), true);
    if (!empty($GLOBALS['dsap_token_retry_mode']) && (int) ($request['max_tokens'] ?? 0) > 4096) {
        return [
            'response' => ['code' => 422],
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['error' => ['message' => 'max_tokens exceeds this model token limit']]),
        ];
    }
    if (($GLOBALS['dsap_nvidia_posts'] ?? 0) === 1) {
        $content = json_encode([
            'urls' => [
                ['url' => 'https://sources.example/official-a'],
                ['url' => 'https://sources.example/official-b'],
                ['url' => 'https://sources.example/official-c'],
            ],
        ], JSON_UNESCAPED_SLASHES);
    } else {
        $content = json_encode([
            'primary_keyword' => 'NVIDIA only',
            'sources' => [
                ['url' => 'https://sources.example/official-a'],
                ['url' => 'https://sources.example/official-b'],
                ['url' => 'https://sources.example/official-c'],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
    return [
        'response' => ['code' => 200],
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode([
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'request' => $request,
        ]),
    ];
}

function wp_safe_remote_get(string $url, array $args = []): array|WP_Error
{
    unset($args);
    $GLOBALS['dsap_nvidia_gets']++;
    return [
        'response' => ['code' => 200],
        'headers' => ['content-type' => 'text/html; charset=UTF-8'],
        'body' => '<html><head><title>Verified source</title></head><body><main>'
            . str_repeat('This is verified reference content for the requested SEO research. ', 8)
            . '</main></body></html>',
        'url' => $url,
    ];
}

function wp_remote_retrieve_response_code(array|WP_Error $response): int
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
}

function wp_remote_retrieve_body(array|WP_Error $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function wp_remote_retrieve_header(array|WP_Error $response, string $name): string
{
    return is_array($response) ? (string) ($response['headers'][strtolower($name)] ?? '') : '';
}

require dirname(__DIR__) . '/includes/AiClientInterface.php';
require dirname(__DIR__) . '/includes/NvidiaAiClient.php';

use DSAP\NvidiaAiClient;

$client = new NvidiaAiClient('nvapi-test', 'nvidia/test-model');
$result = $client->respond('research_v1', ['type' => 'object'], 'Research NVIDIA-only publishing.', true, '', true, 'resp_legacy_openai');
if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}
if (($result['usage']['provider'] ?? '') !== 'nvidia' || (int) ($result['usage']['grounding_source_count'] ?? 0) !== 3) {
    throw new RuntimeException('NVIDIA provider or grounding metadata was missing.');
}
if (($GLOBALS['dsap_nvidia_posts'] ?? 0) !== 2 || ($GLOBALS['dsap_nvidia_gets'] ?? 0) !== 3) {
    throw new RuntimeException('NVIDIA source discovery and grounded generation did not both run.');
}
if (($result['sources'] ?? []) !== [
    'https://sources.example/official-a',
    'https://sources.example/official-b',
    'https://sources.example/official-c',
]) {
    throw new RuntimeException('Only verified source URLs were not returned.');
}

$GLOBALS['dsap_token_retry_mode'] = true;
$GLOBALS['dsap_nvidia_posts'] = 0;
$retry = $client->respond('article_v1', ['type' => 'object'], 'Write an article.');
if (is_wp_error($retry) || ($GLOBALS['dsap_nvidia_posts'] ?? 0) !== 3) {
    throw new RuntimeException('NVIDIA model max_tokens validation did not reduce and retry.');
}

echo "provider=nvidia discovery_calls=1 generation_calls=1 verified_sources=3 legacy_response_id=ignored token_limit=adaptive\n";
