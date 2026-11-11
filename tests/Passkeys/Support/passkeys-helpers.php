<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

if (! class_exists('PasskeyTestUser')) {
    class PasskeyTestUser extends Model implements Authenticatable
    {
        protected $table   = 'passkey_test_users';
        protected $guarded = [];
        protected $hidden  = ['password'];
        protected $casts   = [
            'is_platform_admin'     => 'boolean',
            'force_password_change' => 'boolean',
            'two_factor_enabled'    => 'boolean',
            'email_verified_at'     => 'datetime',
        ];

        /** Contextos permitidos (columna CSV para el test). */
        public function hasContext(string $context): bool
        {
            return in_array($context, array_filter(explode(',', (string) $this->contexts)), true);
        }

        public function getAuthIdentifier(): mixed { return $this->id; }
        public function getAuthIdentifierName(): string { return 'id'; }
        public function getAuthPassword(): string { return (string) $this->password; }
        public function getRememberToken(): ?string { return null; }
        public function setRememberToken($value): void {}
        public function getRememberTokenName(): string { return ''; }
        public function getAuthPasswordName(): string { return 'password'; }
    }
}

if (! function_exists('passkeysSetUp')) {
    function passkeysSetUp(): void
    {
        Schema::dropIfExists('passkey_test_users');
        Schema::create('passkey_test_users', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->string('contexts')->default('backoffice');
            $t->boolean('is_platform_admin')->default(false);
            $t->string('platform_role')->nullable();
            $t->boolean('force_password_change')->default(false);
            $t->boolean('two_factor_enabled')->default(false);
            $t->string('otp_mode')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();
        });

        config()->set('auth.providers.users.model', PasskeyTestUser::class);
        config()->set('innertia.auth.user_model', PasskeyTestUser::class);
        config()->set('innertia.contexts', ['backoffice' => [], 'portal' => []]);
        config()->set('jwt.secret', str_repeat('k', 64));
        config()->set('innertia.passkeys.enabled', true);
        config()->set('innertia.passkeys.rp_id', 'localhost');
        config()->set('innertia.passkeys.origins', ['http://localhost']);
    }
}

if (! function_exists('passkeysTearDown')) {
    function passkeysTearDown(): void
    {
        Schema::dropIfExists('passkey_test_users');
    }
}

if (! function_exists('passkeyUser')) {
    function passkeyUser(array $attrs = []): PasskeyTestUser
    {
        // refresh(): carga los defaults de BD (p. ej. contexts) que create() no trae.
        return PasskeyTestUser::create($attrs + [
            'name'              => 'Ana',
            'email'             => 'ana' . uniqid() . '@test.cl',
            'password'          => Hash::make('Secreta123!'),
            'email_verified_at' => now(),
        ])->refresh();
    }
}
