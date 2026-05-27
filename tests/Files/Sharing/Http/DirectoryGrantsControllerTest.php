<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\Routes as DirectoryRoutes;

class FakeDirGrantUser extends Model implements Authenticatable
{
    use HasUuids;
    protected $table    = 'fake_grant_users';
    public $timestamps  = false;
    protected $fillable = ['id'];
    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthPassword(): string { return ''; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return ''; }
    public function getAuthPasswordName(): string { return 'password'; }
}

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaShareMigrateUp();
    DirectoryRoutes::register();
});

afterEach(fn () => innertiaShareMigrateDown());

function makeGrantDir(string $name = 'dir'): Directory
{
    return Directory::createIn(null, $name);
}

function makeGrantUser(): FakeDirGrantUser
{
    $u = new FakeDirGrantUser;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    return $u;
}

it('POST /directories/{id}/grants creates a grant', function () {
    $dir  = makeGrantDir('docs');
    $user = makeGrantUser();

    $response = $this->postJson("/directories/{$dir->id}/grants", [
        'grantable_type'  => 'user',
        'grantable_id'    => $user->id,
        'grantable_class' => FakeDirGrantUser::class,
        'action'          => 'access',
    ]);

    $response->assertStatus(201)
             ->assertJsonStructure(['data' => ['id', 'entity_type', 'entity_id', 'grantable_type', 'grantable_id', 'action']]);

    expect(EntityPermission::where('entity_id', $dir->id)->where('grantable_id', $user->id)->exists())->toBeTrue();
});

it('POST /directories/{id}/grants returns 404 for unknown directory', function () {
    $this->postJson('/directories/nonexistent-uuid/grants', [
        'grantable_type'  => 'user',
        'grantable_id'    => (string) \Illuminate\Support\Str::uuid(),
        'grantable_class' => FakeDirGrantUser::class,
        'action'          => 'access',
    ])->assertStatus(404);
});

it('DELETE /directories/{id}/grants revokes a grant', function () {
    $dir  = makeGrantDir('docs');
    $user = makeGrantUser();

    EntityPermission::grant($dir, $user, 'access');

    $this->deleteJson("/directories/{$dir->id}/grants", [
        'grantable_class' => FakeDirGrantUser::class,
        'grantable_id'    => $user->id,
        'action'          => 'access',
    ])->assertStatus(204);

    expect(EntityPermission::where('entity_id', $dir->id)->where('grantable_id', $user->id)->exists())->toBeFalse();
});

it('GET /directories/{id}/grants lists grants', function () {
    $dir  = makeGrantDir('docs');
    $user = makeGrantUser();

    EntityPermission::grant($dir, $user, 'access');

    $response = $this->getJson("/directories/{$dir->id}/grants");

    $response->assertStatus(200)
             ->assertJsonStructure(['data' => [['id', 'entity_type', 'entity_id', 'action']]]);
});
