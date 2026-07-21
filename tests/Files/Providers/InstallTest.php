<?php

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaProvidersMigrateUp();
});

afterEach(fn () => innertiaProvidersMigrateDown());

it('crea la tabla provider_connections con las columnas clave', function () {
    expect(Schema::hasTable('provider_connections'))->toBeTrue();
    foreach (['id', 'provider', 'scope', 'user_id', 'tenant_id', 'email', 'access_token', 'refresh_token', 'expires_at', 'shared_drive_id', 'app_folder_id'] as $col) {
        expect(Schema::hasColumn('provider_connections', $col))->toBeTrue();
    }
});

it('agrega las columnas de enlace a files', function () {
    foreach (['provider', 'link_mode', 'external_id', 'external_url', 'external_account_id'] as $col) {
        expect(Schema::hasColumn('files', $col))->toBeTrue();
    }
});
