<?php

namespace Innertia\PublicApi\Docs;

/**
 * Reusable JSON Schemas (OpenAPI components) referenced by name from descriptors.
 *
 *   ApiSchemas::register('Call', [
 *       'type' => 'object',
 *       'properties' => ['id' => ['type' => 'string'], 'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound']]],
 *       'required' => ['id'],
 *   ]);
 */
final class ApiSchemas
{
    /** @var array<string, array> */
    private static array $schemas = [];

    public static function register(string $name, array $schema): void
    {
        self::$schemas[$name] = $schema;
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$schemas;
    }

    public static function has(string $name): bool
    {
        return isset(self::$schemas[$name]);
    }

    public static function flush(): void
    {
        self::$schemas = [];
    }
}
