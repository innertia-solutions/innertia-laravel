<?php

namespace Innertia\Platform\Organizations\UseCases;

use Innertia\Platform\Contracts\OrganizationContract;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Organizations\Models\Organization;

class UpdateOrganization extends UseCase
{
    public function __construct(
        public readonly int|string $id,
        public readonly ?string    $name   = null,
        public readonly ?string    $key    = null,
        public readonly ?bool      $active = null,
        public readonly array      $extra  = [],
    ) {}

    /**
     * Atributos a actualizar. NULL-filtered: solo se aplican los campos pasados.
     * Override en subclases para custom mapping. El array $extra ya viene mergeado
     * (se aplican tal cual, sin filtro de null — la app decide qué pasa).
     */
    protected function attributes(): array
    {
        return array_merge(
            array_filter([
                'name'   => $this->name,
                'key'    => $this->key,
                'active' => $this->active,
            ], fn ($v) => $v !== null),
            $this->extra,
        );
    }

    public function execute(): OrganizationContract
    {
        $model = config('innertia.organizations.model', Organization::class);
        $org   = $model::findOrFail($this->id);

        $old = [
            'name'   => $org->name,
            'key'    => $org->key,
            'active' => $org->active,
        ];

        $org->fill($this->attributes());
        $org->save();

        $new = [
            'name'   => $org->name,
            'key'    => $org->key,
            'active' => $org->active,
        ];

        event(new \Innertia\Platform\Organizations\Events\OrganizationUpdated($org, ['old' => $old, 'new' => $new]));

        return $org;
    }
}
