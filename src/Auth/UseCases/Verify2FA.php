<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Auth\Services\JwtService;
use Innertia\Platform\Contracts\UseCase;
use PragmaRX\Google2FA\Google2FA;

class Verify2FA extends UseCase
{
    public function __construct(
        protected JwtService $jwt,
        protected Authenticatable $user,
        protected string $code,
    ) {}

    public function execute(): array
    {
        $google2fa = new Google2FA();
        $secret    = decrypt($this->user->two_factor_secret);

        if (! $google2fa->verifyKey($secret, $this->code)) {
            throw new \Illuminate\Validation\ValidationException(
                validator([], []),
                response()->json(['message' => 'Invalid 2FA code.'], 422)
            );
        }

        // Confirm 2FA enabled on first successful verify
        if (! $this->user->two_factor_enabled) {
            $this->user->forceFill(['two_factor_enabled' => true])->save();
        }

        $token = $this->jwt->generateToken($this->user);

        return ['token' => $token, 'user' => $this->user];
    }
}
