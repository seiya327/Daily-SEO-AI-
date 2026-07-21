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
        if (!is_wp_error($result) || !$this->shouldFallback($result->get_error_code())) {
            return $result;
        }

        $fallback = $this->fallback->respond($schemaName, $schema, $prompt, $webSearch, '', false, '');
        if (is_wp_error($fallback)) {
            $retryable = in_array($fallback->get_error_code(), ['dsap_nvidia_network', 'dsap_nvidia_retryable'], true);
            return new \WP_Error(
                $retryable ? 'dsap_ai_fallback_retryable' : 'dsap_ai_fallback_failed',
                $result->get_error_message() . ' / NVIDIA fallback failed: ' . $fallback->get_error_message(),
                ['primary' => $result->get_error_code(), 'fallback' => $fallback->get_error_code()]
            );
        }

        $fallback['fallback_provider'] = 'nvidia';
        $fallback['primary_error'] = $result->get_error_message();
        return $fallback;
    }

    private function shouldFallback(string $errorCode): bool
    {
        return in_array($errorCode, [
            'dsap_openai_quota',
            'dsap_openai_output_limit',
            'dsap_openai_network',
            'dsap_openai_retryable',
            'dsap_openai_background_failed',
            'dsap_openai_response_missing',
            'dsap_openai_empty_output',
            'dsap_openai_bad_json',
            'dsap_openai_schema_json',
        ], true);
    }
}
