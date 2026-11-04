<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Innertia\Auth\Services\JwtService;
use Innertia\Auth\UseCases\ChangePassword;
use Innertia\Auth\UseCases\SetPassword;
use Innertia\Exceptions\UnprocessableException;

/**
 * password/change y password/set son endpoints PRE-AUTH (sin sesión): reciben
 * un user_id y devuelven un JWT. Sin guards, cualquiera que conozca un user_id
 * tomaba la cuenta. Hotfix: change solo con cambio forzado pendiente; set solo
 * para cuentas nunca activadas.
 */
class PreAuthPwUser extends Model implements Authenticatable
{
    protected $table   = 'preauth_pw_users';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts   = ['force_password_change' => 'boolean', 'email_verified_at' => 'datetime'];

    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthPassword(): string { return (string) $this->password; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return ''; }
    public function getAuthPasswordName(): string { return 'password'; }
}

beforeEach(function () {
    Schema::create('preauth_pw_users', function ($t) {
        $t->increments('id');
        $t->string('email');
        $t->string('password')->nullable();
        $t->boolean('force_password_change')->default(false);
        $t->timestamp('email_verified_at')->nullable();
    });

    config()->set('auth.providers.users.model', PreAuthPwUser::class);
    Mail::fake();

    $jwt = Mockery::mock(JwtService::class);
    $jwt->shouldReceive('generateToken')->andReturn('fake-token');
    app()->instance(JwtService::class, $jwt);
});

afterEach(fn () => Schema::dropIfExists('preauth_pw_users'));

function pwUser(array $attrs = []): PreAuthPwUser
{
    return PreAuthPwUser::create(array_merge([
        'email'    => 'u'.uniqid().'@x.cl',
        'password' => Hash::make('Original123'),
    ], $attrs));
}

it('change rechaza una cuenta sin cambio forzado pendiente (toma de cuenta)', function () {
    $user = pwUser(['force_password_change' => false, 'email_verified_at' => now()]);

    expect(fn () => (new ChangePassword((string) $user->id, 'Hacked1234', 'Hacked1234', 'backoffice'))->execute())
        ->toThrow(UnprocessableException::class);

    expect(Hash::check('Original123', $user->fresh()->password))->toBeTrue();
});

it('change rechaza un user_id inexistente con el mismo error', function () {
    expect(fn () => (new ChangePassword('999999', 'Hacked1234', 'Hacked1234', 'backoffice'))->execute())
        ->toThrow(UnprocessableException::class);
});

it('change funciona cuando el login exigió el cambio forzado', function () {
    $user = pwUser(['force_password_change' => true]);

    $result = (new ChangePassword((string) $user->id, 'Nueva12345', 'Nueva12345', 'backoffice'))->execute();

    expect($result['token'])->toBe('fake-token')
        ->and($user->fresh()->force_password_change)->toBeFalse()
        ->and(Hash::check('Nueva12345', $user->fresh()->password))->toBeTrue();

    // Ya consumido: un segundo intento falla.
    expect(fn () => (new ChangePassword((string) $user->id, 'Otra123456', 'Otra123456', 'backoffice'))->execute())
        ->toThrow(UnprocessableException::class);
});

it('set rechaza una cuenta ya activada', function () {
    $user = pwUser(['email_verified_at' => now()]);

    expect(fn () => (new SetPassword((string) $user->id, 'Hacked1234', 'Hacked1234', 'backoffice'))->execute())
        ->toThrow(UnprocessableException::class);

    expect(Hash::check('Original123', $user->fresh()->password))->toBeTrue();
});

it('set activa una cuenta nunca activada una sola vez', function () {
    $user = pwUser(['email_verified_at' => null]);

    $result = (new SetPassword((string) $user->id, 'Nueva12345', 'Nueva12345', 'backoffice'))->execute();

    expect($result['token'])->toBe('fake-token')
        ->and($user->fresh()->email_verified_at)->not->toBeNull();

    expect(fn () => (new SetPassword((string) $user->id, 'Otra123456', 'Otra123456', 'backoffice'))->execute())
        ->toThrow(UnprocessableException::class);
});
