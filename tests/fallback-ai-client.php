<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/includes/AiClientInterface.php';
require dirname(__DIR__) . '/includes/FallbackAiClient.php';

use DSAP\AiClientInterface;
use DSAP\FallbackAiClient;

final class TestAiClient implements AiClientInterface
{
    public array $calls = [];

    public function __construct(private array|WP_Error $result)
    {
    }

    public function respond(string $schemaName, array $schema, string $prompt, bool $webSearch = false, string $model = '', bool $background = false, string $responseId = ''): array|WP_Error
    {
        $this->calls[] = compact('schemaName', 'schema', 'prompt', 'webSearch', 'model', 'background', 'responseId');
        return $this->result;
    }
}

$fallbackCodes = [
    'dsap_openai_quota',
    'dsap_openai_output_limit',
    'dsap_openai_network',
    'dsap_openai_retryable',
    'dsap_openai_background_failed',
    'dsap_openai_response_missing',
    'dsap_openai_empty_output',
    'dsap_openai_bad_json',
    'dsap_openai_schema_json',
];

foreach ($fallbackCodes as $code) {
    $primary = new TestAiClient(new WP_Error($code, 'OpenAI failed'));
    $nvidia = new TestAiClient(['data' => ['ok' => true], 'usage' => ['provider' => 'nvidia']]);
    $client = new FallbackAiClient($primary, $nvidia);
    $result = $client->respond('research_v1', ['type' => 'object'], 'prompt', true, 'gpt-5.6-terra', true, 'resp_existing');
    if (is_wp_error($result) || ($result['fallback_provider'] ?? '') !== 'nvidia') {
        throw new RuntimeException("{$code} did not switch to NVIDIA.");
    }
    $fallbackCall = $nvidia->calls[0] ?? [];
    if (($fallbackCall['responseId'] ?? 'invalid') !== '' || !empty($fallbackCall['background']) || empty($fallbackCall['webSearch'])) {
        throw new RuntimeException("{$code} passed invalid resume arguments to NVIDIA.");
    }
}

$primary = new TestAiClient(new WP_Error('dsap_openai_permanent', 'Bad API key'));
$nvidia = new TestAiClient(['data' => ['ok' => true]]);
$result = (new FallbackAiClient($primary, $nvidia))->respond('article_v1', [], 'prompt');
if (!is_wp_error($result) || $result->get_error_code() !== 'dsap_openai_permanent' || $nvidia->calls !== []) {
    throw new RuntimeException('Permanent OpenAI configuration errors must not be hidden by NVIDIA fallback.');
}

$primary = new TestAiClient(new WP_Error('dsap_openai_output_limit', 'Output limit'));
$nvidia = new TestAiClient(new WP_Error('dsap_nvidia_network', 'Temporary NVIDIA network error'));
$result = (new FallbackAiClient($primary, $nvidia))->respond('research_v1', [], 'prompt', true);
if (!is_wp_error($result) || $result->get_error_code() !== 'dsap_ai_fallback_retryable') {
    throw new RuntimeException('Temporary NVIDIA fallback failure was not marked retryable.');
}

echo "fallback_codes=" . count($fallbackCodes) . " output_limit=nvidia nvidia_network=retryable permanent=primary\n";
