<?php

namespace Innertia;

use Innertia\Exceptions\NotFoundException;
use Innertia\Saas\Models\Tenant;
use Innertia\Saas\TenantContext;

/**
 * API pública para tenant management.
 *
 *   Innertia::tenant()          → Tenant|null  (tenant activo en runtime)
 *   Innertia::tenant('acme')    → Tenant|null  (busca por key; null en App mode)
 *   Innertia::activate('acme')  → Tenant|null  (busca + setea; null en App mode)
 *   Innertia::deactivate()      → void         (limpia contexto; no-op en App mode)
 *
 * Todos los métodos son no-op / devuelven null en App mode (isSaas = false).
 */
class InnertiaManager
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly bool          $isSaas,
    ) {}

    /**
     * Sin argumento: devuelve el tenant activo en runtime.
     * Con argumento: busca el tenant por key en la BD (nunca setea el contexto).
     */
    public function tenant(?string $key = null): ?Tenant
    {
        if (! $this->isSaas) {
            return null;
        }

        if ($key === null) {
            return $this->context->get();
        }

        return $this->findByKey($key);
    }

    /**
     * Busca el tenant por key y lo setea como activo en el contexto.
     * Devuelve null en App mode; devuelve el Tenant si lo encuentra.
     *
     * @throws NotFoundException si el tenant no existe (solo en SaaS mode)
     */
    public function activate(string $key): ?Tenant
    {
        if (! $this->isSaas) {
            return null;
        }

        $tenant = $this->findByKey($key);

        if (! $tenant) {
            throw new NotFoundException("Tenant \"{$key}\" not found.");
        }

        $this->context->set($tenant);

        return $tenant;
    }

    /**
     * Limpia el tenant activo del contexto. No-op en App mode.
     */
    public function deactivate(): void
    {
        if (! $this->isSaas) {
            return;
        }

        $this->context->clear();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findByKey(string $key): ?Tenant
    {
        /** @var class-string<Tenant> $model */
        $model = config('innertia.saas.tenant_model', Tenant::class);

        return $model::findByKey($key);
    }
}
