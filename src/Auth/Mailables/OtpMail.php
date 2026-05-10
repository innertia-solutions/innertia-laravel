<?php

namespace Innertia\Auth\Mailables;

use Innertia\Mail\InnertiaMailable;
use Innertia\Models\UserOtp;

class OtpMail extends InnertiaMailable
{
    public function __construct(
        public readonly UserOtp $otp,
        public readonly string  $action,
    ) {}

    public function subject(): string
    {
        return match ($this->action) {
            'login'              => 'Tu código de verificación',
            'email_verification' => 'Verifica tu correo electrónico',
            'password_reset'     => 'Restablece tu contraseña',
            'sensitive_action'   => 'Confirmación de acción sensible',
            default              => 'Tu código OTP',
        };
    }

    public function view(): string
    {
        return 'innertia::mail.otp';
    }

    protected function payload(): array
    {
        return [
            'code'   => $this->otp->code,
            'action' => $this->action,
        ];
    }
}
