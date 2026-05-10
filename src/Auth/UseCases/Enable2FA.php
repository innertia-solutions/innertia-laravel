<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Platform\Contracts\UseCase;
use PragmaRX\Google2FA\Google2FA;

class Enable2FA extends UseCase
{
    public function execute(Authenticatable $user): array
    {
        $google2fa = new Google2FA();
        $secret    = $google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret'  => encrypt($secret),
            'two_factor_enabled' => false, // confirmed on first verify
        ])->save();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );

        return [
            'secret'      => $secret,
            'qr_code_url' => $qrCodeUrl,
        ];
    }
}
