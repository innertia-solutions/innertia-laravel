<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Innertia\Auth\Services\JwtService;

/**
 * Renovación de la sesión de la consola /platform: el token nuevo conserva el
 * claim `platform` (antes se perdía y el BFF no podía renovar esas sesiones).
 */
class PlatformRefreshUser extends Model implements Authenticatable
{
    protected $table   = 'platform_refresh_users';
    protected $guarded = [];
    protected $casts   = ['is_platform_admin' => 'boolean', 'force_password_change' => 'boolean'];

    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthPassword(): string { return (string) $this->password; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return ''; }
    public function getAuthPasswordName(): string { return 'password'; }
}

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Schema::dropIfExists('platform_refresh_users');
    Schema::create('platform_refresh_users', function ($t) {
        $t->increments('id');
        $t->string('email')->unique();
        $t->string('password')->nullable();
        $t->boolean('is_platform_admin')->default(false);
        $t->boolean('force_password_change')->default(false);
        $t->timestamps();
    });
    if (! Schema::hasTable('user_sessions')) {
        Schema::create('user_sessions', function ($t) {
            $t->id();
            $t->string('user_id');
            $t->string('token_hash')->unique();
            $t->string('device_id')->nullable();
            $t->string('ip')->nullable();
            $t->text('browser')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }

    config()->set('auth.providers.users.model', PlatformRefreshUser::class);
    config()->set('jwt.secret', str_repeat('k', 64));

    \Innertia\Auth\Routes::platformRoutes();
});

afterEach(fn () => Schema::dropIfExists('platform_refresh_users'));

it('platform/auth/refresh renueva el token conservando el claim platform', function () {
    $user  = PlatformRefreshUser::create(['email' => 'platform@innertia.cl', 'is_platform_admin' => true]);
    $jwt   = app(JwtService::class);
    $token = $jwt->generateToken($user, ['platform' => true]);

    $new = $this->withToken($token)->postJson('platform/auth/refresh')->assertOk()->json('token');

    expect($new)->toBeString()->not->toBe($token)
        ->and($jwt->decode($new)->platform ?? null)->toBeTrue()
        ->and($jwt->decode($new)->sub)->toBe((string) $user->id)
        // El token anterior queda invalidado y el nuevo autentica.
        ->and($jwt->getUserFromToken($token))->toBeNull()
        ->and($jwt->getUserFromToken($new)?->id)->toBe($user->id);
});

it('refreshToken conserva context y no agrega platform a un token de usuario', function () {
    $user  = PlatformRefreshUser::create(['email' => 'u@x.cl']);
    $jwt   = app(JwtService::class);

    $claims = $jwt->decode($jwt->refreshToken($jwt->generateToken($user, ['context' => 'backoffice'])));

    expect($claims->context)->toBe('backoffice')
        ->and(isset($claims->platform))->toBeFalse();
});

it('no renueva un token de plataforma si la cuenta dejó de ser de plataforma', function () {
    $user  = PlatformRefreshUser::create(['email' => 'platform@innertia.cl', 'is_platform_admin' => true]);
    $jwt   = app(JwtService::class);
    $token = $jwt->generateToken($user, ['platform' => true]);

    $user->forceFill(['is_platform_admin' => false])->save();

    expect(fn () => $jwt->refreshToken($token))->toThrow(\RuntimeException::class);
    // No se invalida el token: el rechazo no cierra la sesión por sí mismo.
    expect($jwt->getUserFromToken($token))->not->toBeNull();
});

it('platform/auth/refresh exige autenticación', function () {
    $this->postJson('platform/auth/refresh')->assertUnauthorized();
});
