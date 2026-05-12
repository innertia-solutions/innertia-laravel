<?php

namespace Innertia\Users\UseCases;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Innertia\Auth\Mailables\WelcomeMail;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

class CreateUser extends UseCase
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $email,
        public readonly string  $password,
        public readonly ?string $role = null,
        public readonly bool    $forcePasswordChange = false,
        public readonly bool    $sendWelcome = true,
    ) {}

    public function execute(): mixed
    {
        $model = config('auth.providers.users.model');

        if ($model::where('email', $this->email)->exists()) {
            throw new ConflictException("A user with email \"{$this->email}\" already exists.");
        }

        $user = $model::create([
            'name'                  => $this->name,
            'email'                 => $this->email,
            'password'              => Hash::make($this->password),
            'force_password_change' => $this->forcePasswordChange,
        ]);

        if ($this->role) {
            $user->assignRole($this->role);
        }

        if ($this->sendWelcome) {
            // Pass plain password only when force_password_change is set,
            // so the user can see their temporary credentials in the email.
            $temporaryPassword = $this->forcePasswordChange ? $this->password : null;

            Mail::to($user->email)->queue(new WelcomeMail($user, $temporaryPassword));
        }

        return $user;
    }
}
