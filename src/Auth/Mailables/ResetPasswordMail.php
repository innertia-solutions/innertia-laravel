<?php

namespace Innertia\Auth\Mailables;

use Innertia\Mail\Branding;
use Innertia\Mail\InnertiaMailable;

/** Password reset link with the platform brand (replaces Laravel's generic template). */
class ResetPasswordMail extends InnertiaMailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $resetUrl,
        public readonly int $expiresInMinutes = 60,
    ) {}

    public function subjectLine(): string
    {
        return 'Restablece tu contraseña — ' . Branding::platform()->name;
    }

    public function markdownView(): string
    {
        return 'innertia::mail.reset-password';
    }

    public function mailBranding(): ?Branding
    {
        return Branding::platform();
    }

    public function emailType(): ?string
    {
        return 'account.password_reset';
    }

    protected function payload(): array
    {
        return ['name' => $this->name, 'resetUrl' => $this->resetUrl, 'expiresInMinutes' => $this->expiresInMinutes];
    }
}
