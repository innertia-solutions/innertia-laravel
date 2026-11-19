<?php

namespace Innertia\Auth\EmailChange\UseCases;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Innertia\Auth\EmailChange\EmailChangeStore;
use Innertia\Auth\Mailables\EmailChangedMail;
use Innertia\Mail\EmailLogging;
use Innertia\Notifications\Models\Notification;
use Innertia\Notifications\NoticeTypes;
use Innertia\Notifications\Recipients;
use Innertia\Notifications\Support\NotifyLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * Confirms an email change with the 6-digit code: updates the email (verified), notifies the
 * PREVIOUS address (account.email_changed, critical) and leaves a bell item.
 */
class ConfirmEmailChange extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly string $code,
    ) {}

    public function execute(): array
    {
        $pending = EmailChangeStore::get($this->userId);

        if (! $pending) {
            throw ValidationException::withMessages(['code' => 'El código venció, pide uno nuevo.']);
        }

        if ((int) $pending['attempts'] >= EmailChangeStore::MAX_ATTEMPTS) {
            EmailChangeStore::forget($this->userId);
            throw ValidationException::withMessages(['code' => 'Demasiados intentos. Pide un código nuevo.']);
        }

        if (! EmailChangeStore::matches($pending, trim($this->code))) {
            $attempts = EmailChangeStore::recordFailure($this->userId, $pending);
            if ($attempts >= EmailChangeStore::MAX_ATTEMPTS) {
                EmailChangeStore::forget($this->userId);
                throw ValidationException::withMessages(['code' => 'Demasiados intentos. Pide un código nuevo.']);
            }
            throw ValidationException::withMessages(['code' => 'El código no es correcto.']);
        }

        $model = Recipients::userModel();
        $user = $model::query()->findOrFail($this->userId);
        $newEmail = (string) $pending['email'];

        if (RequestEmailChange::emailTaken($model, $newEmail, (string) $user->getKey())) {
            EmailChangeStore::forget($this->userId);
            throw ValidationException::withMessages(['code' => 'Ese correo ya está en uso.']);
        }

        $oldEmail = (string) $user->email;
        $user->forceFill(['email' => $newEmail]);
        if (Schema::hasColumn($user->getTable(), 'email_verified_at')) {
            $user->forceFill(['email_verified_at' => now()]);
        }
        $user->save();
        EmailChangeStore::forget($this->userId);

        $mail = new EmailChangedMail((string) ($user->name ?? ''), $newEmail, now()->format('d/m/Y H:i'));
        EmailLogging::queued($mail, $oldEmail, (string) $user->getKey());
        Mail::to($oldEmail)->queue($mail);

        Notification::create([
            'user_id'    => (string) $user->getKey(),
            'type'       => NoticeTypes::ACCOUNT_EMAIL_CHANGED,
            'key'        => NoticeTypes::ACCOUNT_EMAIL_CHANGED,
            'title'      => 'El correo de tu cuenta cambió',
            'body'       => "Ahora usas {$newEmail}.",
            'data'       => ['priority' => 'high'],
            'created_at' => now(),
        ]);

        NotifyLog::info('email changed', ['user_id' => (string) $user->getKey()]);

        return ['updated' => true, 'email' => $newEmail];
    }
}
