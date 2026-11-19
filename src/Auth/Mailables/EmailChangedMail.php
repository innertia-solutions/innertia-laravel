<?php

namespace Innertia\Auth\Mailables;

use Innertia\Mail\Branding;
use Innertia\Mail\InnertiaMailable;
use Innertia\Notifications\NoticeTypes;

/** Security notice sent to the PREVIOUS address after an email change. Platform brand. */
class EmailChangedMail extends InnertiaMailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $newEmail,
        public readonly string $datetime,
    ) {}

    public function subjectLine(): string
    {
        return 'El correo de tu cuenta cambió — ' . Branding::platform()->name;
    }

    public function markdownView(): string
    {
        return 'innertia::mail.email-changed';
    }

    public function mailBranding(): ?Branding
    {
        return Branding::platform();
    }

    public function emailType(): ?string
    {
        return NoticeTypes::ACCOUNT_EMAIL_CHANGED;
    }

    protected function payload(): array
    {
        return ['name' => $this->name, 'newEmail' => self::mask($this->newEmail), 'datetime' => $this->datetime];
    }

    /** a***@dominio.cl — the old inbox may be compromised: do not reveal the full new address. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . str_repeat('*', max(2, mb_strlen($local) - 1)) . '@' . $domain;
    }
}
