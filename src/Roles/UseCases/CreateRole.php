<?php

namespace Innertia\Roles\UseCases;

use Innertia\Models\Role;
use Innertia\Platform\Contracts\UseCase;

class CreateRole extends UseCase
{
    public function __construct(
        public readonly string  $name,
        public readonly ?string $description = null,
    ) {}

    public function execute(): mixed
    {
        $tenantId = (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;

        return Role::createUnique($this->name, $this->description, $tenantId);
    }
}
