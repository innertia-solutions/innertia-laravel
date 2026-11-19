<?php

namespace Innertia\Auth\EmailChange\UseCases;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Innertia\Auth\EmailChange\EmailChangeStore;
use Innertia\Auth\Mailables\EmailChangeCodeMail;
use Innertia\Mail\EmailLogging;
use Innertia\Notifications\Recipients;
use Innertia\Platform\Contracts\UseCase;

/**
 * Starts an email change: checks the current password (when given/required) and that the new
 * address is free (422 without revealing where it is used), then sends a 6-digit code to the
 * NEW address (TTL 15 min, 5 attempts).
 */
class RequestEmailChange extends UseCase
{
    public function __construct(
        public readonly string $userId,
        public readonly string $newEmail,
        public readonly ?string $currentPassword = null,
        public readonly bool $requirePassword = true,
    ) {}

    public function execute(): array
    {
        $model = Recipients::userModel();
        $user = $model::query()->findOrFail($this->userId);
        $email = strtolower(trim($this->newEmail));

        if ($this->requirePassword && ! Hash::check((string) $this->currentPassword, (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es correcta.']);
        }

        if (strtolower((string) $user->email) === $email) {
            throw ValidationException::withMessages(['email' => 'Ese ya es el correo de tu cuenta.']);
        }

        if (self::emailTaken($model, $email, (string) $user->getKey())) {
            throw ValidationException::withMessages(['email' => 'Ese correo ya está en uso.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        EmailChangeStore::put((string) $user->getKey(), $email, $code);

        $mail = new EmailChangeCodeMail((string) ($user->name ?? ''), $code, EmailChangeStore::TTL_MINUTES);
        EmailLogging::queued($mail, $email, (string) $user->getKey());
        Mail::to($email)->queue($mail);

        return ['sent' => true, 'email' => $email, 'expires_in_minutes' => EmailChangeStore::TTL_MINUTES];
    }

    public static function emailTaken(string $model, string $email, string $exceptId): bool
    {
        $instance = new $model;
        $query = method_exists($model, 'bootSoftDeletes') ? $model::withTrashed() : $model::query();

        return $query->whereRaw('LOWER(email) = ?', [$email])
            ->where($instance->getKeyName(), '!=', $exceptId)
            ->exists();
    }
}
