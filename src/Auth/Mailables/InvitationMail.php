<?php

namespace Innertia\Auth\Mailables;

use Innertia\Mail\Branding;
use Innertia\Mail\InnertiaMailable;
use Innertia\Notifications\NoticeTypes;

/** Account invitation: link to create the password (valid N days). Platform brand. */
class InvitationMail extends InnertiaMailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $activationUrl,
        public readonly int $expiresInDays = 7,
    ) {}

    public function subjectLine(): string
    {
        return 'Te damos la bienvenida a ' . Branding::platform()->name;
    }

    public function markdownView(): string
    {
        return 'innertia::mail.invitation';
    }

    public function mailBranding(): ?Branding
    {
        return Branding::platform();
    }

    public function emailType(): ?string
    {
        return NoticeTypes::ACCOUNT_INVITATION;
    }

    protected function payload(): array
    {
        return ['name' => $this->name, 'activationUrl' => $this->activationUrl, 'expiresInDays' => $this->expiresInDays];
    }
}
