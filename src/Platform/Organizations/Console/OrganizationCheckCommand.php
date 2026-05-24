<?php

namespace Innertia\Platform\Organizations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Traits\HasOrganization;

/**
 * CI-friendly guard. Walks PHP files under --scan (default: app/), loads each
 * class, and for every Eloquent model that uses HasOrganization verifies:
 *
 *   1. Its table is declared in config('innertia.organizations.tables').
 *   2. The configured column exists on that table.
 *
 * Fails loud (non-zero exit) on the first incoherence so the build breaks.
 */
class OrganizationCheckCommand extends Command
{
    protected $signature = 'innertia:organization:check
        {--scan= : Directory to scan for models (default: app/)}
    ';
    protected $description = 'Verify every model that uses HasOrganization has a matching table + column.';

    public function handle(): int
    {
        if (! config('innertia.organizations.enabled')) {
            $this->warn('organizations.enabled is false — nothing to check.');
            return self::SUCCESS;
        }

        $scan      = $this->option('scan') ?: app_path();
        $declared  = (array) config('innertia.organizations.tables', []);
        $column    = config('innertia.organizations.column', 'organization_id');
        $problems  = [];

        foreach ($this->classesUsingTrait($scan, HasOrganization::class) as $class) {
            $instance = new $class;
            $table    = $instance->getTable();

            if (! in_array($table, $declared, true)) {
                $problems[] = "[{$class}] uses HasOrganization but table '{$table}' is not in config('innertia.organizations.tables').";
                continue;
            }

            if (! Schema::hasColumn($table, $column)) {
                $problems[] = "[{$class}] table '{$table}' is missing column '{$column}'. Run `php artisan innertia:organization:install` and migrate.";
            }
        }

        if (! empty($problems)) {
            foreach ($problems as $p) {
                $this->error($p);
            }
            return self::FAILURE;
        }

        $this->info('All HasOrganization models are coherent with config + schema.');
        return self::SUCCESS;
    }

    /**
     * @return iterable<class-string>
     */
    private function classesUsingTrait(string $scan, string $trait): iterable
    {
        if (! is_dir($scan)) {
            return [];
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scan));
        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getRealPath());
            if (! preg_match('/namespace\s+([^;]+);/m', $code, $ns)) {
                continue;
            }
            if (! preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $cls)) {
                continue;
            }
            $fqcn = trim($ns[1]) . '\\' . $cls[1];
            if (! class_exists($fqcn)) {
                @require_once $file->getRealPath();
            }
            if (! class_exists($fqcn)) {
                continue;
            }
            $uses = class_uses_recursive($fqcn);
            if (in_array($trait, $uses, true)) {
                yield $fqcn;
            }
        }
    }
}
