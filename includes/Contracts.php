<?php

declare(strict_types=1);

namespace DSAP;

final class Contracts
{
    private static ?array $contracts = null;

    public static function schema(string $name): array
    {
        $contracts = self::load();
        $schema = $contracts['schemas'][$name] ?? null;

        return is_array($schema) ? $schema : [];
    }

    private static function load(): array
    {
        if (self::$contracts !== null) {
            return self::$contracts;
        }

        $raw = file_get_contents(DSAP_DIR . 'contracts.json');
        $json = is_string($raw) ? json_decode($raw, true) : null;
        self::$contracts = is_array($json) ? $json : ['schemas' => []];

        return self::$contracts;
    }
}
