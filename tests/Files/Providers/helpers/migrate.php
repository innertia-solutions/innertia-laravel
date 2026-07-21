<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function innertiaProvidersMigrateUp(): void
{
    require_once __DIR__ . '/../../helpers/migrate.php';
    innertiaFilesMigrateUp();

    if (! Schema::hasTable('provider_connections')) {
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('scope');                 // user | platform | tenant
            $table->uuid('user_id')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('shared_drive_id')->nullable();
            $table->string('app_folder_id')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'scope', 'user_id', 'tenant_id']);
        });
    }

    foreach (['provider', 'external_id', 'external_url'] as $col) {
        if (! Schema::hasColumn('files', $col)) {
            Schema::table('files', fn (Blueprint $t) => $t->string($col)->nullable());
        }
    }

    if (! Schema::hasColumn('files', 'external_account_id')) {
        Schema::table('files', fn (Blueprint $t) => $t->uuid('external_account_id')->nullable());
    }

    if (! Schema::hasColumn('files', 'link_mode')) {
        Schema::table('files', fn (Blueprint $t) => $t->string('link_mode')->default('local'));
    }
}

function innertiaProvidersMigrateDown(): void
{
    Schema::dropIfExists('provider_connections');
}
