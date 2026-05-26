<?php

use Illuminate\Database\Eloquent\Model;
use Innertia\InnertiaManager;
use Innertia\Saas\Models\Tenant;
use Innertia\Saas\TenantContext;
use Innertia\Tags\Models\Tag;

// Helper: activate a fake tenant by injecting it directly into the TenantContext singleton.
// The Tag.tenant_id column is a string; Tenant.getKey() returns the 'id' attribute (bigInt).
// We forceFill integer IDs — SQLite stores them without issues in a TEXT-affinity column.
function setActiveTenant(int $id): Tenant
{
    $tenant = (new Tenant())->forceFill(['id' => $id]);
    app()->make(TenantContext::class)->set($tenant);
    return $tenant;
}

function clearActiveTenant(): void
{
    app()->make(TenantContext::class)->clear();
}

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'saas');

    // Reset the InnertiaManager singleton so isSaas() is re-evaluated from config.
    app()->forgetInstance(InnertiaManager::class);

    // Force Eloquent to re-boot Tag (and re-register HasTenant scopes/listeners)
    // using the updated config value set above.
    Model::clearBootedModels();

    require_once __DIR__ . '/helpers/migrate.php';
    innertiaTagsMigrateUp();
});

afterEach(function () {
    clearActiveTenant();
    innertiaTagsMigrateDown();
    Model::clearBootedModels();
});

it('auto-populates tenant_id when creating in saas mode with active tenant', function () {
    setActiveTenant(1);

    $tag = Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    expect($tag->tenant_id)->toEqual(1);
});

it('isolates tags between tenants via global scope', function () {
    // Tenant A creates a tag.
    setActiveTenant(1);
    Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    expect(Tag::count())->toBe(1);

    // Switch to Tenant B — should see zero tags from tenant A.
    setActiveTenant(2);

    expect(Tag::count())->toBe(0);

    // Tenant B can create its own "urgente" without a unique-index collision.
    Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    expect(Tag::count())->toBe(1);
});

it('does not scope when mode is app', function () {
    config()->set('innertia.mode', 'app');

    // Re-create the manager (no-op mode) and re-boot Tag without the global scope.
    app()->forgetInstance(InnertiaManager::class);
    Model::clearBootedModels();

    Tag::create(['name' => 'Urgente', 'slug' => 'urgente']);

    expect(Tag::count())->toBe(1);
});
