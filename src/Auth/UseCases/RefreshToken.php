<?php

namespace Innertia\Auth\UseCases;

use Innertia\Auth\Services\JwtService;
use Innertia\Platform\Contracts\UseCase;

class RefreshToken extends UseCase
{
    public function __construct(protected JwtService $jwt) {}

    public function execute(string $token): string
    {
        return $this->jwt->refreshToken($token);
    }
}
