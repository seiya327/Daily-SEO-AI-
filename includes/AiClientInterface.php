<?php

declare(strict_types=1);

namespace DSAP;

interface AiClientInterface
{
    /**
     * @return array{data?: array, sources?: array, usage?: array, pending?: bool, response_id?: string, status?: string}
     */
    public function respond(string $schemaName, array $schema, string $prompt, bool $webSearch = false, string $model = '', bool $background = false, string $responseId = ''): array|\WP_Error;
}
