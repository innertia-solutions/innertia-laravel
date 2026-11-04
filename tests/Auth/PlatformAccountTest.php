<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Auth\Platform\PlatformAccount;

/**
 * Cuenta owner de /platform: platform@innertia.cl con clave temporal, cambio
 * obligatorio al entrar (Authenticate bloquea el resto) y reset por artisan.
 */
class PlatformAcctUser extends Model implements Authenticatable
{
    protected $table   = 'platform_acct_users';
    protected $guarded = [];
    protected $hidden  = ['password'];
    protected $casts   = ['is_platform_admin' => 'boolean', 'force_password_change' => 'boolean', 'email_verified_at' => 'datetime'];

    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthPassword(): string { return (string) $this->password; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return ''; }
    public function getAuthPasswordName(): string { return 'password'; }
}

beforeEach(function () {
    Schema::create('platform_acct_users', function ($t) {
        $t->increments('id');
        $t->string('name')->nullable();
        $t->string('email')->unique();
        $t->string('password')->nullable();
        $t->boolean('is_platform_admin')->default(false);
        $t->string('platform_role')->nullable();
        $t->boolean('force_password_change')->default(false);
        $t->timestamp('email_verified_at')->nullable();
        $t->timestamps();
    });
    config()->set('auth.providers.users.model', PlatformAcctUser::class);
    config()->set('innertia.platform.separate_identity', true);

    \Innertia\Auth\Routes::platformRoutes();
    Route::middleware(Authenticate::class)->get('platform/ping', fn () => ['ok' => true]);
});

afterEach(fn () => Schema::dropIfExists('platform_acct_users'));

function platformAcct(): PlatformAcctUser
{
    return PlatformAcctUser::where('email', 'platform@innertia.cl')->firstOrFail();
}

it('ensureDefault crea la cuenta owner con cambio obligatorio y no pisa una existente', function () {
    config()->set('innertia.platform.default_password', 'Inicial12345');

    expect(PlatformAccount::ensureDefault())->toBe('Inicial12345');
    $u = platformAcct();
    expect($u->is_platform_admin)->toBeTrue()
        ->and($u->platform_role)->toBe('owner')
        ->and($u->force_password_change)->toBeTrue()
        ->and(Hash::check('Inicial12345', $u->password))->toBeTrue();

    $u->forceFill(['password' => Hash::make('YaCambiada123'), 'force_password_change' => false])->save();
    expect(PlatformAccount::ensureDefault())->toBeNull();
    expect(Hash::check('YaCambiada123', platformAcct()->password))->toBeTrue();
});

it('fuera de local y sin default_password la clave por defecto es aleatoria', function () {
    app()->detectEnvironment(fn () => 'production');

    $pwd = PlatformAccount::ensureDefault();

    expect($pwd)->not->toBe('Innertia1234!')->and(strlen($pwd))->toBe(32);
});

it('se crea sola al terminar migrate cuando la identidad separada está activa', function () {
    Event::dispatch(new \Illuminate\Database\Events\MigrationsEnded('up'));

    expect(platformAcct()->force_password_change)->toBeTrue();
});

it('con cambio pendiente solo deja cambiar la clave; después la consola funciona', function () {
    PlatformAccount::reset(password: 'Temporal12345');
    $this->actingAs(platformAcct(), 'jwt');

    $this->getJson('platform/ping')->assertForbidden()->assertJsonPath('error', 'password_change_required');

    $this->postJson('platform/auth/password', ['password' => 'Nueva123456', 'password_confirmation' => 'Nueva123456'])
        ->assertOk()
        ->assertJsonPath('user.force_password_change', false);

    $this->getJson('platform/ping')->assertOk();
    expect(Hash::check('Nueva123456', platformAcct()->password))->toBeTrue();
});

it('valida confirmación, fortaleza y que la clave nueva sea distinta', function () {
    PlatformAccount::reset(password: 'Temporal12345');
    $this->actingAs(platformAcct(), 'jwt');

    $this->postJson('platform/auth/password', ['password' => 'Nueva123456', 'password_confirmation' => 'Otra'])->assertUnprocessable();
    $this->postJson('platform/auth/password', ['password' => 'corta', 'password_confirmation' => 'corta'])->assertUnprocessable();
    $this->postJson('platform/auth/password', ['password' => 'Temporal12345', 'password_confirmation' => 'Temporal12345'])->assertUnprocessable();
});

it('platform:password genera una clave temporal que vuelve a exigir el cambio', function () {
    PlatformAccount::reset(password: 'Temporal12345');
    platformAcct()->forceFill(['force_password_change' => false])->save();

    $this->artisan('platform:password', ['--password' => 'Reset1234567'])
        ->expectsOutputToContain('Reset1234567')
        ->assertSuccessful();

    expect(Hash::check('Reset1234567', platformAcct()->password))->toBeTrue()
        ->and(platformAcct()->force_password_change)->toBeTrue();
});

it('platform:password crea la cuenta si no existe y no toca cuentas que no son de plataforma', function () {
    $this->artisan('platform:password')->assertSuccessful();
    expect(platformAcct()->is_platform_admin)->toBeTrue();

    $cliente = PlatformAcctUser::create(['email' => 'cliente@x.cl', 'password' => Hash::make('Cliente12345')]);
    $this->artisan('platform:password', ['--email' => 'cliente@x.cl'])->assertFailed();
    expect($cliente->fresh()->is_platform_admin)->toBeFalse();
});

it('no bloquea a usuarios que no son de plataforma', function () {
    $u = PlatformAcctUser::create(['email' => 'u@x.cl', 'force_password_change' => true]);
    $this->actingAs($u, 'jwt');

    $this->getJson('platform/ping')->assertOk();
});
