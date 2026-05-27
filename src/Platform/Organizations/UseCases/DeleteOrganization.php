<?php

namespace Innertia\Platform\Organizations\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Organizations\Models\Organization;

class DeleteOrganization extends UseCase
{
    public function __construct(public readonly int|string $id) {}

    public function execute(): void
    {
        $model = config('innertia.organizations.model', Organization::class);
        $org   = $model::findOrFail($this->id);

        $id   = $org->id;
        $key  = $org->key;
        $name = $org->name;

        $org->delete();

        event(new \Innertia\Platform\Organizations\Events\OrganizationDeleted($id, $key, $name));
    }
}
