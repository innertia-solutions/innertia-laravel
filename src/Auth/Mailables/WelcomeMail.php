<?php

namespace Innertia\Auth\Mailables;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Mail\InnertiaMailable;

class WelcomeMail extends InnertiaMailable
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly ?string $temporaryPassword = null,
    ) {}

    public function subject(): string
    {
        return '¡Bienvenido a ' . config('app.name') . '!';
    }

    public function view(): string
    {
        return 'innertia::mail.welcome';
    }

    protected function payload(): array
    {
        return [
            'user'              => $this->user,
            'temporaryPassword' => $this->temporaryPassword,
            'loginUrl'          => rtrim(config('app.url'), '/') . config('innertia.mail.login_path', '/login'),
        ];
    }
}
