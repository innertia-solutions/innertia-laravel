<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\Hash;
use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Platform\Contracts\UseCase;

class PlatformLogin extends UseCase
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {}

    public function execute(): array
    {
        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $this->email)->first();

        if (! $user || ! Hash::check($this->password, $user->password)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'Invalid credentials.'], 422)
            );
        }

        if (! $user->is_platform_admin) {
            throw new ForbiddenException('Esta cuenta no tiene acceso al panel de plataforma.');
        }

        $token = app(JwtService::class)->generateToken($user, ['platform' => true]);

        return ['token' => $token, 'user' => $user];
    }
}
