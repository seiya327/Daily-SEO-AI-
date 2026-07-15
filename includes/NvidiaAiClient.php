<?php

declare(strict_types=1);

namespace DSAP;

final class NvidiaAiClient implements AiClientInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
        $this->model = $model !== '' ? $model : 'nvidia/llama-3.3-nemotron-super-49b-v1';
    }

    public function respond(string $schemaName, array $schema, string $prompt, bool $webSearch = false, string $model = '', bool $background = false, string $responseId = ''): array|\WP_Error
    {
        unset($model, $background);

        if ($responseId !== '') {
            return new \WP_Error('dsap_nvidia_response_missing', 'NVIDIA fallback cannot resume an OpenAI background response. Start a new run to use NVIDIA.');
        }

        $body = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Return only valid JSON. Do not wrap it in Markdown. Match the schema as closely as possible.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($schemaName, $schema, $prompt, $webSearch),
                ],
            ],
            'temperature' => 0.2,
            'max_tokens' => in_array($schemaName, ['article_v1', 'refresh_article_v1'], true) ? 12000 : 8000,
        ];

        $response = wp_remote_post('https://integrate.api.nvidia.com/v1/chat/completions', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('dsap_nvidia_network', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && isset($json['error']['message']) ? (string) $json['error']['message'] : 'NVIDIA API request failed.';
            return new \WP_Error(in_array($code, [408, 409, 429, 500, 502, 503, 504], true) ? 'dsap_nvidia_retryable' : 'dsap_nvidia_permanent', $message, ['status' => $code]);
        }

        if (!is_array($json)) {
            return new \WP_Error('dsap_nvidia_bad_json', 'NVIDIA API returned invalid JSON.');
        }

        $content = (string) ($json['choices'][0]['message']['content'] ?? '');
        $text = $this->extractJsonText($content);
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return new \WP_Error('dsap_nvidia_schema_json', 'NVIDIA output did not parse as JSON.');
        }

        return [
            'data' => $data,
            'sources' => $this->extractDeclaredSources($data),
            'usage' => is_array($json['usage'] ?? null) ? array_merge($json['usage'], ['provider' => 'nvidia']) : ['provider' => 'nvidia'],
            'status' => 'completed',
        ];
    }

    private function buildPrompt(string $schemaName, array $schema, string $prompt, bool $webSearch): string
    {
        $parts = [
            'Schema name: ' . $schemaName,
            'JSON schema:',
            wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        if ($webSearch) {
            $parts[] = 'Live web search is not available in NVIDIA fallback. Use only the provided site context and avoid inventing source URLs.';
        }
        $parts[] = 'Task prompt:';
        $parts[] = $prompt;

        return implode("\n\n", $parts);
    }

    private function extractJsonText(string $content): string
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
            $content = trim($content);
        }
        if ($content !== '' && ($content[0] === '{' || $content[0] === '[')) {
            return $content;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($content, $start, $end - $start + 1);
        }

        return $content;
    }

    private function extractDeclaredSources(array $data): array
    {
        $sources = [];
        $candidates = [];
        if (is_array($data['sources'] ?? null)) {
            $candidates = array_merge($candidates, $data['sources']);
        }
        if (is_array($data['research']['sources'] ?? null)) {
            $candidates = array_merge($candidates, $data['research']['sources']);
        }
        if (is_array($data['article']['sources'] ?? null)) {
            $candidates = array_merge($candidates, $data['article']['sources']);
        }

        foreach ($candidates as $source) {
            $url = is_array($source) ? (string) ($source['url'] ?? '') : (string) $source;
            $url = esc_url_raw($url);
            if ($url !== '') {
                $sources[] = $url;
            }
        }

        return array_values(array_unique($sources));
    }
}
