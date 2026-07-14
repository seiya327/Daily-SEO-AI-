<?php

declare(strict_types=1);

namespace DSAP;

interface AiClientInterface
{
    /**
     * @return array{data?: array, sources?: array, usage?: array}
     */
    public function respond(string $schemaName, array $schema, string $prompt, bool $webSearch = false, string $model = ''): array|\WP_Error;
}
