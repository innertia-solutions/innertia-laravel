<?php

namespace Innertia\Saas;

use Innertia\Saas\Models\Tenant;

/**
 * Mantiene el Tenant activo para el request/job/command actual.
 * Se registra como singleton en InnertiaServiceProvider.
 */
class TenantContext
{
    private ?Tenant $current = null;

    public function set(Tenant $tenant): void
    {
        $this->current = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->current;
    }

    public function clear(): void
    {
        $this->current = null;
    }
}
