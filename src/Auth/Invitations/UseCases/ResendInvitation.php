<?php

namespace Innertia\Auth\Invitations\UseCases;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Innertia\Platform\Contracts\UseCase;

/**
 * Resends an invitation: new link (the previous one stops working), throttled to
 * config('innertia.auth.invitation_resend_per_hour', 3) per user and hour.
 */
class ResendInvitation extends UseCase
{
    public function __construct(public readonly string $userId) {}

    public function execute(): array
    {
        $key = 'innertia-invitation-resend:' . $this->userId;
        $max = max(1, (int) config('innertia.auth.invitation_resend_per_hour', 3));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException(
                "Ya se reenvió esta invitación {$max} veces en la última hora. Intenta más tarde."
            );
        }

        RateLimiter::hit($key, 3600);

        return (new SendInvitation($this->userId))->execute();
    }
}
