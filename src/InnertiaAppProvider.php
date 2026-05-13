<?php

namespace Innertia;

/**
 * Service provider for single-tenant (app) mode.
 *
 * Register this in bootstrap/providers.php for standard Laravel apps:
 *
 *   return [
 *       Innertia\InnertiaAppProvider::class,
 *       App\AppProvider::class,
 *   ];
 */
class InnertiaAppProvider extends InnertiaServiceProvider
{
    protected function isSaas(): bool
    {
        return false;
    }
}
