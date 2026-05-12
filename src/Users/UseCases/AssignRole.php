<?php

namespace Innertia\Users\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

class AssignRole extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly string $role,
    ) {}

    public function execute(): mixed
    {
        $model = config('auth.providers.users.model');

        $user = $model::find($this->userId);

        if (! $user) {
            throw new NotFoundException("User \"{$this->userId}\" not found.");
        }

        $user->assignRole($this->role);

        return $user;
    }
}
