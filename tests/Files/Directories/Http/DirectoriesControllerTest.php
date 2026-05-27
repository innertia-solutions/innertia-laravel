<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Innertia\Files\Directories\Http\Controllers\DirectoriesController;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\CreateDirectory;

class CustomDirectoriesController extends DirectoriesController
{
    protected function extraStoreRules(): array
    {
        return ['project_id' => 'required|string'];
    }
}

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaDirectoriesMigrateUp();

    Route::middleware([])->group(function () {
        \Innertia\Files\Directories\Routes::register();
    });
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

// ── index ─────────────────────────────────────────────────────────────────────

it('lists root directories', function () {
    (new CreateDirectory(null, 'Alpha'))->execute();
    (new CreateDirectory(null, 'Beta'))->execute();

    $response = $this->getJson('/directories');

    $response->assertOk()->assertJsonCount(2, 'data');
});

it('does not list non-root directories in index', function () {
    $root  = (new CreateDirectory(null, 'Root'))->execute();
    (new CreateDirectory($root, 'Child'))->execute();

    $response = $this->getJson('/directories');

    $response->assertOk()->assertJsonCount(1, 'data');
});

// ── show ──────────────────────────────────────────────────────────────────────

it('shows a directory with breadcrumbs', function () {
    $root  = (new CreateDirectory(null, 'Root'))->execute();
    $child = (new CreateDirectory($root, 'Child'))->execute();

    $response = $this->getJson("/directories/{$child->id}");

    $response->assertOk()
        ->assertJsonPath('data.name', 'Child')
        ->assertJsonPath('data.parent_id', $root->id);

    expect($response->json('data.breadcrumbs'))->toHaveCount(2);
    expect($response->json('data.breadcrumbs.0.name'))->toBe('Root');
    expect($response->json('data.breadcrumbs.1.name'))->toBe('Child');
});

// ── store ─────────────────────────────────────────────────────────────────────

it('creates a root directory via POST', function () {
    $response = $this->postJson('/directories', ['name' => 'Reports']);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Reports')
        ->assertJsonPath('data.parent_id', null);

    expect(Directory::where('name', 'Reports')->exists())->toBeTrue();
});

it('creates a child directory via POST with parent_id', function () {
    $parent = (new CreateDirectory(null, 'Root'))->execute();

    $response = $this->postJson('/directories', [
        'name'      => 'Child',
        'parent_id' => $parent->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Child')
        ->assertJsonPath('data.parent_id', $parent->id);
});

it('includes breadcrumbs in store response', function () {
    $root = (new CreateDirectory(null, 'Root'))->execute();

    $response = $this->postJson('/directories', [
        'name'      => 'Child',
        'parent_id' => $root->id,
    ]);

    $response->assertCreated();
    expect($response->json('data.breadcrumbs'))->toHaveCount(2);
});

it('rejects duplicate name on store (409)', function () {
    (new CreateDirectory(null, 'Reports'))->execute();

    $this->postJson('/directories', ['name' => 'Reports'])
        ->assertStatus(409);
});

// ── update ────────────────────────────────────────────────────────────────────

it('renames a directory via PATCH', function () {
    $dir = (new CreateDirectory(null, 'OldName'))->execute();

    $this->patchJson("/directories/{$dir->id}", ['name' => 'NewName'])
        ->assertOk()
        ->assertJsonPath('data.name', 'NewName');
});

it('moves a directory to a new parent via PATCH', function () {
    $root    = (new CreateDirectory(null, 'Root'))->execute();
    $target  = (new CreateDirectory(null, 'Target'))->execute();
    $child   = (new CreateDirectory($root, 'Child'))->execute();

    $this->patchJson("/directories/{$child->id}", ['parent_id' => $target->id])
        ->assertOk()
        ->assertJsonPath('data.parent_id', $target->id);
});

it('moves a directory to root via PATCH with parent_id null', function () {
    $root  = (new CreateDirectory(null, 'Root'))->execute();
    $child = (new CreateDirectory($root, 'Child'))->execute();

    $response = $this->patchJson("/directories/{$child->id}", ['parent_id' => null]);

    $response->assertOk()
        ->assertJsonPath('data.parent_id', null);
});

// ── destroy ───────────────────────────────────────────────────────────────────

it('soft-deletes a directory via DELETE', function () {
    $dir = (new CreateDirectory(null, 'ToDelete'))->execute();

    $this->deleteJson("/directories/{$dir->id}")
        ->assertNoContent();

    expect(Directory::find($dir->id))->toBeNull();
    expect(Directory::withTrashed()->find($dir->id))->not->toBeNull();
});

it('hard-deletes a directory with force+cascade', function () {
    $dir = (new CreateDirectory(null, 'ToHardDelete'))->execute();

    $this->deleteJson("/directories/{$dir->id}?force=true&cascade=true")
        ->assertNoContent();

    expect(Directory::withTrashed()->find($dir->id))->toBeNull();
});

// ── restore ───────────────────────────────────────────────────────────────────

it('restores a trashed directory via POST restore', function () {
    $dir = (new CreateDirectory(null, 'Trashed'))->execute();
    $dir->trash();

    $this->postJson("/directories/{$dir->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.name', 'Trashed');

    expect(Directory::find($dir->id))->not->toBeNull();
});

// ── trash ─────────────────────────────────────────────────────────────────────

it('lists trashed directories', function () {
    $dir = (new CreateDirectory(null, 'Trashed'))->execute();
    $dir->trash();

    $response = $this->getJson('/directories/trash');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('empties the trash via POST', function () {
    $dir = (new CreateDirectory(null, 'Trashed'))->execute();
    $dir->trash();

    $this->postJson('/directories/trash/empty')
        ->assertOk()
        ->assertJsonPath('deleted', 1);

    expect(Directory::withTrashed()->count())->toBe(0);
});

// ── 404 ───────────────────────────────────────────────────────────────────────

it('returns 404 for unknown directory id', function () {
    $this->getJson('/directories/00000000-0000-0000-0000-000000000000')
        ->assertNotFound();
});

// ── Extensibility ─────────────────────────────────────────────────────────────

it('applies extraStoreRules from subclass', function () {
    Route::middleware([])->group(function () {
        \Innertia\Files\Directories\Routes::register(
            'ext-directories',
            CustomDirectoriesController::class
        );
    });

    $this->postJson('/ext-directories', ['name' => 'X'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['project_id']);
});

// ── children_count ────────────────────────────────────────────────────────────

it('includes children_count when requested', function () {
    $root  = (new CreateDirectory(null, 'Root'))->execute();
    (new CreateDirectory($root, 'Child1'))->execute();
    (new CreateDirectory($root, 'Child2'))->execute();

    $response = $this->getJson("/directories/{$root->id}?include=children_count");

    $response->assertOk();
    expect($response->json('data.children_count'))->toBe(2);
});
