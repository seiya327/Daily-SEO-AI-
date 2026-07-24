<?php

declare(strict_types=1);

namespace DSAP;

final class AiClientFactory
{
    public static function create(array $settings, string $nvidiaApiKey): AiClientInterface
    {
        if (!empty($settings['mock_mode']) || $nvidiaApiKey === '') {
            return new MockAiClient();
        }

        return new NvidiaAiClient($nvidiaApiKey, (string) ($settings['nvidia_model'] ?? ''));
    }
}
