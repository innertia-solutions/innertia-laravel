<?php

namespace Innertia\Auth\RBAC\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

class DeleteUser extends UseCase
{
    public function __construct(
        public readonly string $userId,
    ) {
       parent::__construct();
       
    }

    public function execute(): void
    {
        $model = config('auth.providers.users.model');

        $user = $model::find($this->userId);

        if (! $user) {
            throw new NotFoundException("User \"{$this->userId}\" not found.");
        }

        $user->delete();
    }
}
