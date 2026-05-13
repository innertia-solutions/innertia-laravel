<?php

namespace Innertia;

/**
 * Service provider for multi-tenant SaaS mode.
 *
 * Register this in bootstrap/providers.php for SaaS applications:
 *
 *   return [
 *       Innertia\InnertiaSaasProvider::class,
 *       App\AppProvider::class,
 *   ];
 */
class InnertiaSaasProvider extends InnertiaServiceProvider
{
    protected function isSaas(): bool
    {
        return true;
    }
}
