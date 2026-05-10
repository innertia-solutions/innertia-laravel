<?php

namespace Innertia\Auth\UseCases;

use Innertia\Auth\Services\JwtService;
use Innertia\Platform\Contracts\UseCase;

class Logout extends UseCase
{
    public function __construct(protected JwtService $jwt) {}

    public function execute(string $token): void
    {
        $this->jwt->invalidateToken($token);
    }
}
