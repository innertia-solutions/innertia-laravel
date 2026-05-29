<?php

namespace Innertia;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Organizations\OrganizationsFeature;
use Innertia\Saas\Models\Tenant;
use Innertia\Saas\TenantContext;

/**
 * API pública del contexto runtime de Innertia.
 *
 *   Innertia::context()         → string|null  (contexto del JWT activo, ej: 'backoffice')
 *   Innertia::tenant()          → Tenant|null  (tenant activo en runtime)
 *   Innertia::tenant('acme')    → Tenant|null  (busca por key; null en App mode)
 *   Innertia::activate('acme')  → Tenant|null  (busca + setea; null en App mode)
 *   Innertia::deactivate()      → void         (limpia contexto; no-op en App mode)
 *   Innertia::organization()    → OrganizationContext|null  (null si org feature disabled)
 *
 * tenant() es no-op / null en App mode (isSaas = false).
 */
class InnertiaManager
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly bool          $isSaas,
        private readonly ?\Innertia\Platform\Organizations\OrganizationContext $organizationContext = null,
    ) {}

    /**
     * Devuelve el contexto embebido en el JWT activo (claim 'context').
     * Retorna null si no hay token válido o no tiene el claim.
     *
     *   Innertia::context()  // → 'backoffice' | 'technician' | null
     */
    public function context(): ?string
    {
        try {
            $token = request()?->bearerToken();
            if (! $token) {
                return null;
            }

            $payload = app(\Innertia\Auth\Services\JwtService::class)->decode($token);

            return $payload?->context ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

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

    /**
     * Returns the OrganizationContext when the feature is enabled, otherwise null.
     */
    public function organization(): ?\Innertia\Platform\Organizations\OrganizationContext
    {
        if (! OrganizationsFeature::isActive()) {
            return null;
        }
        return $this->organizationContext;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findByKey(string $key): ?Tenant
    {
        /** @var class-string<Tenant> $model */
        $model = config('innertia.saas.tenant_model', Tenant::class);

        return $model::findByKey($key);
    }
}
