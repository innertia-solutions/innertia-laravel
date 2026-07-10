<?php

namespace Innertia;

/**
 * Service provider for open SaaS mode.
 *
 * Open mode uses the same tenant machinery as SaaS (HasTenant, Innertia::tenant(),
 * Innertia::activate(), saas migrations) but resolves the active tenant by
 * authenticated user selection rather than subdomain / X-Tenant header.
 *
 * Register this in bootstrap/providers.php for open SaaS applications:
 *
 *   return [
 *       Innertia\InnertiaOpenProvider::class,
 *       App\AppProvider::class,
 *   ];
 */
class InnertiaOpenProvider extends InnertiaServiceProvider
{
    protected function isSaas(): bool { return false; }
    protected function isOpen(): bool { return true; }
}
