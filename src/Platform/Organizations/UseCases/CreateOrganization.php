<?php

namespace Innertia\Platform\Organizations\UseCases;

use Innertia\Platform\Contracts\OrganizationContract;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Organizations\Models\Organization;

/**
 * Patrón de extensión:
 *   - Pasar atributos app-specific via $extra: ['owner_id' => $id, ...]
 *   - Para lógica más profunda (validación cruzada, side-effects), extender
 *     esta clase, override attributes() o execute(), y bindear al container:
 *       $this->app->bind(CreateOrganization::class, MyCreateOrganization::class);
 *     Las apps deben construir vía app()->make() en ese caso. El controller
 *     default usa `new` para mantener la API simple — si necesitás bind,
 *     extiende también el controller.
 */
class CreateOrganization extends UseCase
{
    public function __construct(
        public readonly int|string $tenantId,
        public readonly string     $name,
        public readonly string     $key,
        public readonly bool       $active = true,
        public readonly array      $extra  = [],
    ) {}

    /**
     * Atributos a persistir. Override en subclases para validación o
     * transformación adicional. El array $extra ya viene mergeado.
     */
    protected function attributes(): array
    {
        return array_merge([
            'tenant_id' => $this->tenantId,
            'name'      => $this->name,
            'key'       => $this->key,
            'active'    => $this->active,
        ], $this->extra);
    }

    public function execute(): OrganizationContract
    {
        $model = config('innertia.organizations.model', Organization::class);

        $organization = $model::create($this->attributes());

        event(new \Innertia\Platform\Organizations\Events\OrganizationCreated($organization));

        return $organization;
    }
}
