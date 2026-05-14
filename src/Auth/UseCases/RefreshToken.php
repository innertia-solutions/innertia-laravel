<?php

namespace Innertia\Auth\UseCases;

use Innertia\Auth\Services\JwtService;
use Innertia\Platform\Contracts\UseCase;

class RefreshToken extends UseCase
{
    public function __construct(
        protected JwtService $jwt,
        protected string $token,
    ) {
       
    }

    public function execute(): string
    {
        return $this->jwt->refreshToken($this->token);
    }
}
