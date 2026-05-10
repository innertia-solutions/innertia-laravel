<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Contracts\UseCase;

class Disable2FA extends UseCase
{
    public function execute(Authenticatable $user): void
    {
        $user->forceFill([
            'two_factor_secret'  => null,
            'two_factor_enabled' => false,
        ])->save();
    }
}
