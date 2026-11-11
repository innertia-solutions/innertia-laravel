<?php

namespace Innertia\Passkeys\Mail;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Mail\InnertiaMailable;

class PasskeyAddedMail extends InnertiaMailable
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $passkeyName,
    ) {}

    public function subjectLine(): string
    {
        return 'Agregaste una passkey a tu cuenta — ' . config('app.name');
    }

    public function markdownView(): string
    {
        return 'innertia::mail.passkey-added';
    }

    protected function payload(): array
    {
        return [
            'user'        => $this->user,
            'passkeyName' => $this->passkeyName,
            'datetime'    => now()->format('d/m/Y H:i'),
        ];
    }
}
