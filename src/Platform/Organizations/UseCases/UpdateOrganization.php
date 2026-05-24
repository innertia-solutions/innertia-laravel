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
    ) {}

    public function execute(): OrganizationContract
    {
        $model = config('innertia.organizations.model', Organization::class);
        $org   = $model::findOrFail($this->id);

        $org->fill(array_filter([
            'name'   => $this->name,
            'key'    => $this->key,
            'active' => $this->active,
        ], fn ($v) => $v !== null));

        $org->save();

        return $org;
    }
}
