<?php

namespace Innertia\Auth\Invitations\UseCases;

use Illuminate\Support\Facades\Mail;
use Innertia\Auth\Invitations\ActivationLink;
use Innertia\Auth\Invitations\InvitationBroker;
use Innertia\Auth\Mailables\InvitationMail;
use Innertia\Mail\EmailLogging;
use Innertia\Notifications\NoticeTypes;
use Innertia\Notifications\Recipients;
use Innertia\Platform\Contracts\UseCase;

/**
 * Creates an invitation token (invalidating the previous one) and queues the invitation email
 * (platform brand). Returns the activation URL and its expiry.
 */
class SendInvitation extends UseCase
{
    public function __construct(public readonly string $userId) {}

    /** @return array{url: string, invited_at: string, invitation_expires_at: string, invitation_expired: bool} */
    public function execute(): array
    {
        $model = Recipients::userModel();
        $user = $model::query()->findOrFail($this->userId);

        $token = InvitationBroker::create($user);
        $url = ActivationLink::url((string) $user->email, $token);

        $mail = new InvitationMail(
            name: (string) ($user->name ?? ''),
            activationUrl: $url,
            expiresInDays: InvitationBroker::ttlDays(),
        );

        EmailLogging::queued($mail, (string) $user->email, (string) $user->getKey(), NoticeTypes::ACCOUNT_INVITATION);
        Mail::to((string) $user->email)->queue($mail);

        return ['url' => $url] + (InvitationBroker::status($user) ?? []);
    }
}
