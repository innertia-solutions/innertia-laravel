<?php

namespace Innertia\Auth\RBAC\UseCases;

use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

class UpdateUser extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
    ) {
       
    }

    public function execute(): mixed
    {
        $model = config('auth.providers.users.model');

        $user = $model::find($this->userId);

        if (! $user) {
            throw new NotFoundException("User \"{$this->userId}\" not found.");
        }

        if ($this->email !== null) {
            $exists = $model::where('email', $this->email)
                ->where($user->getKeyName(), '!=', $this->userId)
                ->exists();

            if ($exists) {
                throw new ConflictException("The email \"{$this->email}\" is already taken by another user.");
            }
        }

        $data = array_filter([
            'name'  => $this->name,
            'email' => $this->email,
        ], fn ($value) => $value !== null);

        $user->update($data);

        return $user;
    }
}
