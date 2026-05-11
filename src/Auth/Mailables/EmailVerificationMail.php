<?php

namespace Innertia\Auth\Mailables;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Mail\InnertiaMailable;

class EmailVerificationMail extends InnertiaMailable
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $url,
    ) {}

    public function subject(): string
    {
        return 'Verify your email address';
    }

    public function view(): string
    {
        return 'innertia::mail.email-verification';
    }
}
