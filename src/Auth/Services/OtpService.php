<?php

namespace Innertia\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Auth\Mailables\OtpMail;
use Innertia\Auth\Models\UserOtp;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function send(Authenticatable $user, string $action): UserOtp
    {
        $otp = UserOtp::generate($user, $action);

        Mail::to($user->email)->queue(new OtpMail($otp, $action));

        return $otp;
    }

    public function verify(Authenticatable $user, string $code, string $action): bool
    {
        $otp = UserOtp::where('user_id', $user->getAuthIdentifier())
            ->where('action', $action)
            ->where('active', true)
            ->latest()
            ->first();

        if (! $otp || ! $otp->isValid() || $otp->code !== $code) {
            return false;
        }

        $otp->markAsUsed();

        return true;
    }
}
