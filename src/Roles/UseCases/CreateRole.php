<?php

namespace Innertia\Roles\UseCases;

use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;
use Spatie\Permission\Models\Role;

class CreateRole extends UseCase
{
    public function __construct(
        public readonly string $name,
    ) {}

    public function execute(): mixed
    {
        $exists = Role::where('name', $this->name)
            ->where('guard_name', 'api')
            ->exists();

        if ($exists) {
            throw new ConflictException("A role with name \"{$this->name}\" already exists.");
        }

        return Role::create([
            'name'       => $this->name,
            'guard_name' => 'api',
        ]);
    }
}
