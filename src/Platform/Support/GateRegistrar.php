<?php

namespace Innertia\Platform\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Innertia\Platform\Contracts\Gate as GateContract;

/**
 * Auto-registers Gate classes from a directory following the convention:
 *   CanManageOrders → 'manage-orders'
 *   CanViewReports  → 'view-reports'
 *
 * Usage in AppServiceProvider::boot():
 *   GateRegistrar::fromDirectory(app_path('Domains'), 'App\\Domains');
 */
class GateRegistrar
{
    public static function fromDirectory(string $directory, string $namespace): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(
                ['/', '\\'],
                '\\',
                Str::after($file->getPathname(), str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory) . DIRECTORY_SEPARATOR)
            );

            $class = $namespace . '\\' . Str::replaceLast('.php', '', $relative);

            if (
                class_exists($class)
                && is_subclass_of($class, GateContract::class)
                && ! (new \ReflectionClass($class))->isAbstract()
            ) {
                $ability = static::classToAbility(class_basename($class));
                Gate::define($ability, $class);
            }
        }
    }

    /**
     * CanManageOrders → 'manage-orders'
     * CanViewReports  → 'view-reports'
     */
    protected static function classToAbility(string $className): string
    {
        $name = Str::after($className, 'Can');
        return Str::kebab($name);
    }
}
