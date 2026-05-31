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

    public function subjectLine(): string
    {
        return 'Verify your email address';
    }

    public function markdownView(): string
    {
        return 'innertia::mail.email-verification';
    }
}
