<?php

namespace Innertia\Version;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Qué está desplegado. Dos niveles:
 *  - public():  app, version, commit corto, build_date, environment. NUNCA dependencias.
 *  - details(): lo público + runtime (php/laravel/bd) + paquetes Composer curados.
 *
 * Origen (por campo): config innertia.version.* (env APP_VERSION/APP_COMMIT/APP_BUILD_DATE)
 *   → build-info.json (escrito en el build Docker) → fallback (root package / git local).
 * Se lee con defaults en código: el producto no necesita publicar config.
 */
class VersionInfo
{
    public const DEFAULT_PACKAGES = [
        'laravel/framework',
        'innertia-solutions/laravel-innertia',
        'laravel/ai',
        'laravel/mcp',
        'laravel/octane',
        'laravel/socialite',
        'laravel/tinker',
        'firebase/php-jwt',
        'pusher/pusher-php-server',
        'league/flysystem-aws-s3-v3',
    ];

    private ?array $buildInfo = null;

    /** @return array{app: string, version: ?string, commit: ?string, build_date: ?string, environment: string} */
    public function public(): array
    {
        return [
            'app'         => (string) config('app.name'),
            'version'     => $this->version(),
            'commit'      => $this->shortCommit($this->commit()),
            'build_date'  => $this->pick('build_date'),
            'environment' => app()->environment(),
        ];
    }

    public function details(): array
    {
        return $this->public() + [
            'runtime'  => $this->runtime(),
            'packages' => $this->packages(),
        ];
    }

    private function version(): ?string
    {
        return $this->pick('version', 'app') ?? $this->rootVersion();
    }

    private function commit(): ?string
    {
        if ($commit = $this->pick('commit')) {
            return $commit;
        }
        // Fallbacks VCS (dev local): desactivables con innertia.version.git=false.
        if (! config('innertia.version.git', true)) {
            return null;
        }

        return $this->rootReference() ?? $this->gitCommit();
    }

    /** config (innertia.version.{configKey}) → build-info.json ({fileKey}). */
    private function pick(string $fileKey, ?string $configKey = null): ?string
    {
        $value = config('innertia.version.' . ($configKey ?? $fileKey));
        if (filled($value)) {
            return (string) $value;
        }
        $value = $this->buildInfo()[$fileKey] ?? null;

        return filled($value) && is_scalar($value) ? (string) $value : null;
    }

    private function buildInfo(): array
    {
        if ($this->buildInfo !== null) {
            return $this->buildInfo;
        }
        $path = config('innertia.version.file') ?: base_path('build-info.json');
        $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        return $this->buildInfo = is_array($data) ? $data : [];
    }

    private function shortCommit(?string $commit): ?string
    {
        $commit = $commit !== null ? trim($commit) : null;

        return filled($commit) ? substr($commit, 0, 7) : null;
    }

    private function rootVersion(): ?string
    {
        try {
            $v = InstalledVersions::getRootPackage()['pretty_version'] ?? null;
        } catch (Throwable) {
            return null;
        }
        // Composer sin VCS informa "1.0.0+no-version-set": no aporta.
        return filled($v) && ! str_contains($v, 'no-version-set') ? $v : null;
    }

    private function rootReference(): ?string
    {
        try {
            $ref = InstalledVersions::getRootPackage()['reference'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return filled($ref) ? $ref : null;
    }

    /** Solo dev local: git rev-parse si hay repo accesible. */
    private function gitCommit(): ?string
    {
        try {
            $r = Process::path(base_path())->timeout(2)->run(['git', 'rev-parse', 'HEAD']);

            return $r->successful() ? trim($r->output()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function runtime(): array
    {
        $db = ['driver' => null, 'version' => null];
        try {
            $connection = DB::connection();
            $db['driver'] = $connection->getDriverName();
            $db['version'] = (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            // BD caída o sin configurar: el endpoint de versiones no debe fallar por eso.
        }

        return [
            'php'      => PHP_VERSION,
            'laravel'  => app()->version(),
            'database' => $db,
        ];
    }

    /** @return array<string, array{version: ?string, reference: ?string}> */
    private function packages(): array
    {
        $out = [];
        foreach ((array) config('innertia.version.packages', self::DEFAULT_PACKAGES) as $name) {
            if (! InstalledVersions::isInstalled($name)) {
                continue;
            }
            $out[$name] = [
                'version'   => InstalledVersions::getPrettyVersion($name),
                'reference' => $this->shortCommit(InstalledVersions::getReference($name)),
            ];
        }

        return $out;
    }
}
