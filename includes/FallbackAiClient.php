<?php

declare(strict_types=1);

namespace DSAP;

final class FallbackAiClient implements AiClientInterface
{
    private AiClientInterface $primary;
    private AiClientInterface $fallback;

    public function __construct(AiClientInterface $primary, AiClientInterface $fallback)
    {
        $this->primary = $primary;
        $this->fallback = $fallback;
    }

    public function respond(string $schemaName, array $schema, string $prompt, bool $webSearch = false, string $model = '', bool $background = false, string $responseId = ''): array|\WP_Error
    {
        $result = $this->primary->respond($schemaName, $schema, $prompt, $webSearch, $model, $background, $responseId);
        if (!is_wp_error($result) || $result->get_error_code() !== 'dsap_openai_quota') {
            return $result;
        }

        $fallback = $this->fallback->respond($schemaName, $schema, $prompt, $webSearch, '', false, '');
        if (is_wp_error($fallback)) {
            return new \WP_Error(
                'dsap_ai_fallback_failed',
                $result->get_error_message() . ' / NVIDIA fallback failed: ' . $fallback->get_error_message(),
                ['primary' => $result->get_error_code(), 'fallback' => $fallback->get_error_code()]
            );
        }

        $fallback['fallback_provider'] = 'nvidia';
        $fallback['primary_error'] = $result->get_error_message();
        return $fallback;
    }
}
