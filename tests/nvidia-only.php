<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool
{
    unset($deprecated, $autoload);
    $GLOBALS['dsap_test_options'][$name] = $value;
    return true;
}

function update_option(string $name, mixed $value, bool $autoload = true): bool
{
    unset($autoload);
    $GLOBALS['dsap_test_options'][$name] = $value;
    return true;
}

require dirname(__DIR__) . '/includes/AiClientInterface.php';
require dirname(__DIR__) . '/includes/MockAiClient.php';
require dirname(__DIR__) . '/includes/NvidiaAiClient.php';
require dirname(__DIR__) . '/includes/AiClientFactory.php';
require dirname(__DIR__) . '/includes/Settings.php';

use DSAP\AiClientFactory;
use DSAP\MockAiClient;
use DSAP\NvidiaAiClient;
use DSAP\Settings;

$legacy = array_merge(Settings::defaults(), [
    'openai_api_key' => 'sk-legacy-secret',
    'nvidia_api_key' => 'nvapi-current',
    'nvidia_fallback_enabled' => true,
    'model_research' => 'gpt-5.6-terra',
    'model_audit' => 'gpt-5.6-luna',
    'model_refresh' => 'gpt-5.6-terra',
    'article_image_provider' => 'openai',
    'github_token' => 'stale-public-repository-token',
]);
$GLOBALS['dsap_test_options'][Settings::OPTION] = $legacy;
Settings::ensureDefaults();
$migrated = $GLOBALS['dsap_test_options'][Settings::OPTION];

foreach (['openai_api_key', 'nvidia_fallback_enabled', 'model_research', 'model_audit', 'model_refresh', 'github_token'] as $removed) {
    if (array_key_exists($removed, $migrated)) {
        throw new RuntimeException("Legacy OpenAI setting was not removed: {$removed}");
    }
}
if (($migrated['article_image_provider'] ?? '') !== 'openverse' || !empty($migrated['ai_images_enabled'])) {
    throw new RuntimeException('OpenAI image settings were not migrated to Openverse.');
}

$live = AiClientFactory::create(array_merge(Settings::defaults(), ['mock_mode' => false]), 'nvapi-test');
if (!$live instanceof NvidiaAiClient) {
    throw new RuntimeException('Live AI client was not NVIDIA.');
}
$mock = AiClientFactory::create(array_merge(Settings::defaults(), ['mock_mode' => true]), 'nvapi-test');
if (!$mock instanceof MockAiClient) {
    throw new RuntimeException('Mock mode did not remain isolated from paid APIs.');
}
$missingKey = AiClientFactory::create(array_merge(Settings::defaults(), ['mock_mode' => false]), '');
if (!$missingKey instanceof MockAiClient) {
    throw new RuntimeException('Missing NVIDIA key did not fail closed to mock mode.');
}

echo "provider=nvidia legacy_openai=removed github_token=removed images=openverse mock_mode=isolated\n";
