<?php

namespace Innertia\Auth\Mailables;

use Innertia\Mail\Branding;
use Innertia\Mail\InnertiaMailable;

/** 6-digit code sent to the NEW address to confirm an email change. Platform brand. */
class EmailChangeCodeMail extends InnertiaMailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $code,
        public readonly int $expiresInMinutes = 15,
    ) {}

    public function subjectLine(): string
    {
        return 'Confirma tu nuevo correo — ' . Branding::platform()->name;
    }

    public function markdownView(): string
    {
        return 'innertia::mail.email-change-code';
    }

    public function mailBranding(): ?Branding
    {
        return Branding::platform();
    }

    public function emailType(): ?string
    {
        return 'account.email_change_code';
    }

    protected function payload(): array
    {
        return ['name' => $this->name, 'code' => $this->code, 'expiresInMinutes' => $this->expiresInMinutes];
    }
}
