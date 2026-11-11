<?php

namespace Innertia\Version;

/**
 * Formato estándar de versión de despliegue:
 *   prod: v1.0.2-20261111.2   ·   QA: v1.0.2-20261111-QA.7   ·   dev: v1.0.2-dev
 */
final class VersionString
{
    public const PATTERN = '/^v(\d+\.\d+\.\d+)-(?:(\d{8})(?:-(QA))?\.(\d+)|(dev))$/';

    /** @return array{raw: string, semver: string, date: ?string, environment: string, sequence: ?int}|null */
    public static function parse(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || ! preg_match(self::PATTERN, $raw, $m)) {
            return null;
        }

        if (($m[5] ?? '') === 'dev') {
            return ['raw' => $raw, 'semver' => $m[1], 'date' => null, 'environment' => 'dev', 'sequence' => null];
        }

        return [
            'raw'         => $raw,
            'semver'      => $m[1],
            'date'        => substr($m[2], 0, 4) . '-' . substr($m[2], 4, 2) . '-' . substr($m[2], 6, 2),
            'environment' => ($m[3] ?? '') === 'QA' ? 'qa' : 'production',
            'sequence'    => (int) $m[4],
        ];
    }
}
