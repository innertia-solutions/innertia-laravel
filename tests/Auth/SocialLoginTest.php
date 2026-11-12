<?php

use Innertia\Auth\Services\JwtService;
use Innertia\Auth\Social\SocialLogin;
use Innertia\Auth\Social\SocialProvider;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Exceptions\PlatformAccountException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    passkeysSetUp();
    config()->set('innertia.contexts', ['backoffice' => [], 'examiner' => [], 'school' => []]);
});
afterEach(fn () => passkeysTearDown());

function socialUserFor(?string $email): SocialiteUser
{
    return (new SocialiteUser())->map(['id' => 'g-1', 'email' => $email, 'name' => 'Ana']);
}

function socialLogin(?string $email, array $state = [], ?array $contexts = null): array
{
    return (new SocialLogin(SocialProvider::Google, socialUserFor($email), $state, $contexts))->execute();
}

function socialTokenContext(string $token): ?string
{
    return app(JwtService::class)->decode($token)->context ?? null;
}

// ── Contexto fijo (comportamiento histórico) ───────────────────────────────

it('contexto fijo: emite token para el contexto del state y lo devuelve', function () {
    $user = passkeyUser(['contexts' => 'backoffice']);

    $result = socialLogin($user->email, ['context' => 'backoffice']);

    expect($result['context'])->toBe('backoffice')
        ->and($result['created'])->toBeFalse()
        ->and(socialTokenContext($result['token']))->toBe('backoffice');
});

it('contexto fijo: sin ese contexto responde 403', function () {
    $user = passkeyUser(['contexts' => 'school']);

    socialLogin($user->email, ['context' => 'backoffice']);
})->throws(ForbiddenException::class, 'is not allowed');

it('contexto fijo: correo no registrado responde 404 (login-only)', function () {
    socialLogin('nadie@test.cl', ['context' => 'backoffice']);
})->throws(NotFoundException::class);

// ── Probing (login único) ──────────────────────────────────────────────────

it('probing: entra al primer contexto de la lista que el usuario posea', function () {
    $user = passkeyUser(['contexts' => 'school,examiner']);

    $result = socialLogin($user->email, ['context' => 'backoffice'], ['backoffice', 'examiner', 'school']);

    expect($result['context'])->toBe('examiner')
        ->and(socialTokenContext($result['token']))->toBe('examiner')
        ->and($result['user']->id)->toBe($user->id);
});

it('probing: un colegio solo recibe token de colegio', function () {
    $user = passkeyUser(['contexts' => 'school']);

    $result = socialLogin($user->email, [], ['backoffice', 'examiner', 'school']);

    expect($result['context'])->toBe('school')
        ->and(socialTokenContext($result['token']))->toBe('school');
});

it('probing: ignora contextos desconocidos en config', function () {
    $user = passkeyUser(['contexts' => 'backoffice']);

    $result = socialLogin($user->email, [], ['teacher', 'backoffice']);

    expect($result['context'])->toBe('backoffice');
});

it('probing: sin ningún contexto de la lista responde 403', function () {
    $user = passkeyUser(['contexts' => 'portal']);

    socialLogin($user->email, [], ['backoffice', 'examiner', 'school']);
})->throws(ForbiddenException::class);

it('probing: correo no registrado responde 404 y no usa el provisioner', function () {
    app()->bind(\Innertia\Auth\Social\SocialProvisioner::class, fn () => new class implements \Innertia\Auth\Social\SocialProvisioner {
        public function provision($provider, $socialUser, array $state): mixed
        {
            throw new RuntimeException('no debe provisionar');
        }
    });

    socialLogin('nadie@test.cl', [], ['backoffice']);
})->throws(NotFoundException::class);

it('probing: correo vacío responde 404', function () {
    passkeyUser(['contexts' => 'backoffice']);

    socialLogin(null, [], ['backoffice']);
})->throws(NotFoundException::class);

// ── Cuentas de plataforma ──────────────────────────────────────────────────

it('rechaza cuentas de plataforma con identidad separada (probing)', function () {
    config()->set('innertia.platform.separate_identity', true);
    $admin = passkeyUser(['contexts' => 'backoffice', 'is_platform_admin' => true]);

    socialLogin($admin->email, [], ['backoffice']);
})->throws(PlatformAccountException::class);

it('rechaza cuentas de plataforma con identidad separada (contexto fijo)', function () {
    config()->set('innertia.platform.separate_identity', true);
    $admin = passkeyUser(['contexts' => 'backoffice', 'is_platform_admin' => true]);

    socialLogin($admin->email, ['context' => 'backoffice']);
})->throws(PlatformAccountException::class);
