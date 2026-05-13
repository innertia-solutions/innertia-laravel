<?php

namespace Innertia\Auth\UseCases;

use Illuminate\Support\Facades\URL;
use Innertia\Auth\Mailables\EmailVerificationMail;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

class SendEmailVerification extends UseCase
{
    public function __construct(
        public readonly string $userId,
    ) {
       parent::__construct();
       
    }

    public function execute(): void
    {
        $model = config('auth.providers.users.model');
        $user  = $model::findOrFail($this->userId);

        if ($user->email_verified_at) {
            return;
        }

        $url = URL::temporarySignedRoute(
            'auth.email.verify',
            now()->addMinutes(config('innertia.auth.email_verification.ttl', 60)),
            ['user' => $user->getAuthIdentifier()]
        );

        \Illuminate\Support\Facades\Mail::to($user->email)
            ->queue(new EmailVerificationMail($user, $url));
    }
}
