<?php

namespace Innertia\Auth\RBAC\UseCases;

use Innertia\Auth\RBAC\Models\Role;
use Innertia\Platform\Contracts\UseCase;

class CreateRole extends UseCase
{
    public function __construct(
        public readonly string  $name,
        public readonly ?string $description = null,
    ) {
       parent::__construct();
       
    }

    public function execute(): mixed
    {
        $tenantId = \Innertia\Facades\Innertia::tenant()
            ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
            : null;

        return Role::createUnique($this->name, $this->description, $tenantId);
    }
}
