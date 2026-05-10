<?php

namespace Innertia\Auth\Mailables;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Innertia\Models\UserOtp;

class OtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly UserOtp $otp,
        public readonly string $action,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectForAction());
    }

    public function content(): Content
    {
        return new Content(markdown: 'innertia::mail.otp');
    }

    protected function subjectForAction(): string
    {
        return match ($this->action) {
            'login'               => 'Tu código de verificación',
            'email_verification'  => 'Verifica tu correo electrónico',
            'password_reset'      => 'Restablece tu contraseña',
            'sensitive_action'    => 'Confirmación de acción sensible',
            default               => 'Tu código OTP',
        };
    }
}
