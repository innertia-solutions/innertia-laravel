<?php

namespace Innertia\Auth\Mailables;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Mail\InnertiaMailable;

class PasswordChangedMail extends InnertiaMailable
{
    public function __construct(
        public readonly Authenticatable $user,
    ) {}

    public function subjectLine(): string
    {
        return 'Tu contraseña fue actualizada — ' . config('app.name');
    }

    public function markdownView(): string
    {
        return 'innertia::mail.password-changed';
    }

    protected function payload(): array
    {
        return [
            'user'     => $this->user,
            'datetime' => now()->format('d/m/Y H:i'),
        ];
    }
}
