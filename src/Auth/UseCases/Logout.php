<?php

namespace Innertia\Auth\UseCases;

use Innertia\Auth\Services\JwtService;
use Innertia\Platform\Contracts\UseCase;

class Logout extends UseCase
{
    public function __construct(
        protected JwtService $jwt,
        protected string $token,
    ) {}

    public function execute(): void
    {
        $this->jwt->invalidateToken($this->token);
    }
}
