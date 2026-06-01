<?php

namespace Innertia\Geo;

/**
 * Catálogo geográfico de Chile (país → regiones → comunas) con códigos CUT
 * oficiales del INE. Fuente de validación; el front (innertia-nuxt) tiene la
 * misma data con las mismas keys.
 */
class Geo
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    private static function data(): array
    {
        return self::$data ??= require __DIR__ . '/chile.php';
    }

    /** @return array<int,array{code:string,name:string,communes:array}> */
    public static function regions(): array
    {
        return self::data()['regions'];
    }

    public static function regionName(string $code): ?string
    {
        foreach (self::regions() as $r) {
            if ($r['code'] === $code) return $r['name'];
        }
        return null;
    }

    /** @return array<int,array{code:string,name:string}> */
    public static function communes(string $regionCode): array
    {
        foreach (self::regions() as $r) {
            if ($r['code'] === $regionCode) return $r['communes'];
        }
        return [];
    }

    public static function communeName(string $code): ?string
    {
        foreach (self::regions() as $r) {
            foreach ($r['communes'] as $c) {
                if ($c['code'] === $code) return $c['name'];
            }
        }
        return null;
    }

    public static function communeBelongsToRegion(string $communeCode, string $regionCode): bool
    {
        foreach (self::communes($regionCode) as $c) {
            if ($c['code'] === $communeCode) return true;
        }
        return false;
    }
}
