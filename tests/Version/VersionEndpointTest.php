<?php

use Illuminate\Support\Facades\File;
use Innertia\Version\Routes;
use Innertia\Version\VersionInfo;

beforeEach(function () {
    config()->set('app.name', 'acme');
    config()->set('innertia.version', [
        'app'        => null,
        'commit'     => null,
        'build_date' => null,
        'file'       => sys_get_temp_dir() . '/innertia-build-info-' . getmypid() . '.json',
        'git'        => false,
        'version_file' => sys_get_temp_dir() . '/innertia-VERSION-' . getmypid(),
    ]);
    File::delete(config('innertia.version.file'));
    File::delete(config('innertia.version.version_file'));

    Routes::public();
    Routes::platform('plat-test');
});

afterEach(fn () => File::delete([config('innertia.version.file'), config('innertia.version.version_file')]));

it('el público expone solo app, version, commit, build_date y environment', function () {
    config()->set('innertia.version.app', '1.4.0');
    config()->set('innertia.version.commit', '0123456789abcdef0123456789abcdef01234567');
    config()->set('innertia.version.build_date', '2026-11-04T12:00:00Z');

    $json = $this->getJson('/version')->assertOk()->json();

    expect(array_keys($json))->toBe(['app', 'version', 'version_parts', 'commit', 'build_date', 'environment'])
        ->and($json['version_parts'])->toBeNull()
        ->and($json['app'])->toBe('acme')
        ->and($json['version'])->toBe('1.4.0')
        ->and($json['commit'])->toBe('0123456')
        ->and($json['build_date'])->toBe('2026-11-04T12:00:00Z')
        ->and($json['environment'])->toBe(app()->environment());
});

it('el público nunca incluye versiones de dependencias ni runtime', function () {
    $body = $this->getJson('/version')->assertOk()->getContent();

    expect($body)->not->toContain(PHP_VERSION)
        ->and($body)->not->toContain('laravel')
        ->and($body)->not->toContain('packages')
        ->and($body)->not->toContain('runtime');
});

it('lee commit y fecha de build desde build-info.json cuando no hay env', function () {
    File::put(config('innertia.version.file'), json_encode([
        'commit'     => 'abcdef1234567890',
        'build_date' => '2026-11-01T08:30:00Z',
        'version'    => '2.0.0',
    ]));

    $this->getJson('/version')
        ->assertOk()
        ->assertJsonPath('commit', 'abcdef1')
        ->assertJsonPath('build_date', '2026-11-01T08:30:00Z')
        ->assertJsonPath('version', '2.0.0');
});

it('la config/env tiene precedencia sobre build-info.json', function () {
    File::put(config('innertia.version.file'), json_encode(['commit' => 'ffffffffff']));
    config()->set('innertia.version.commit', '1111111aaaa');

    $this->getJson('/version')->assertJsonPath('commit', '1111111');
});

it('ignora un build-info.json corrupto o vacío', function () {
    File::put(config('innertia.version.file'), '{no es json');

    $this->getJson('/version')->assertOk()->assertJsonPath('commit', null);

    File::put(config('innertia.version.file'), json_encode(['commit' => '']));
    $this->getJson('/version')->assertOk()->assertJsonPath('commit', null);
});

it('el detalle agrega runtime y paquetes instalados', function () {
    $json = $this->getJson('/plat-test/version')->assertOk()->json();

    expect($json)->toHaveKeys(['app', 'version', 'commit', 'build_date', 'environment', 'runtime', 'packages'])
        ->and($json['runtime']['php'])->toBe(PHP_VERSION)
        ->and($json['runtime']['laravel'])->toBe(app()->version())
        ->and($json['packages'])->toHaveKey('laravel/framework')
        ->and($json['packages']['laravel/framework'])->toHaveKeys(['version', 'reference']);
});

it('el detalle omite paquetes configurados que no están instalados', function () {
    config()->set('innertia.version.packages', ['laravel/framework', 'vendor/no-existe']);

    $json = app(VersionInfo::class)->details();

    expect($json['packages'])->toHaveKey('laravel/framework')
        ->and($json['packages'])->not->toHaveKey('vendor/no-existe');
});

it('expone version_parts cuando la versión es estándar', function () {
    config()->set('innertia.version.app', 'v1.0.2-20261111-QA.7');

    $this->getJson('/version')
        ->assertOk()
        ->assertJsonPath('version', 'v1.0.2-20261111-QA.7')
        ->assertJsonPath('version_parts.semver', '1.0.2')
        ->assertJsonPath('version_parts.date', '2026-11-11')
        ->assertJsonPath('version_parts.environment', 'qa')
        ->assertJsonPath('version_parts.sequence', 7);
});

it('en dev sin APP_VERSION usa el archivo VERSION del monorepo', function () {
    File::put(config('innertia.version.version_file'), "1.0.2\n");

    $this->getJson('/version')
        ->assertOk()
        ->assertJsonPath('version', 'v1.0.2-dev')
        ->assertJsonPath('version_parts.environment', 'dev');
});

it('ignora un archivo VERSION inválido', function () {
    File::put(config('innertia.version.version_file'), 'uno-punto-cero');

    $json = $this->getJson('/version')->assertOk()->json();

    expect($json['version'])->not->toBe('vuno-punto-cero-dev');
});
