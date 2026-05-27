<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\RevokeDirectoryShare;
use Innertia\Files\Directories\UseCases\ShareDirectory;

class FakeDirUser extends Model implements Authenticatable
{
    use HasUuids;
    protected $table = 'fake_dir_users';
    public $timestamps = false;
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
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaShareMigrateUp();
});

afterEach(fn () => innertiaShareMigrateDown());

function makeShareDir(?Directory $parent = null, string $name = 'dir'): Directory
{
    return Directory::createIn($parent, $name);
}

function makeFakeDirUser(): FakeDirUser
{
    $u = new FakeDirUser;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    return $u;
}

it('ShareDirectory creates an entity grant with default access action', function () {
    $dir  = makeShareDir(name: 'docs');
    $user = makeFakeDirUser();

    (new ShareDirectory($dir, $user))->execute();

    expect(EntityPermission::where('entity_type', Directory::class)
        ->where('entity_id', $dir->id)
        ->where('grantable_type', FakeDirUser::class)
        ->where('grantable_id', $user->id)
        ->where('action', 'access')
        ->exists()
    )->toBeTrue();
});

it('ShareDirectory accepts a custom action', function () {
    $dir  = makeShareDir(name: 'docs');
    $user = makeFakeDirUser();

    (new ShareDirectory($dir, $user, 'manage'))->execute();

    expect(EntityPermission::where('entity_type', Directory::class)
        ->where('entity_id', $dir->id)
        ->where('grantable_type', FakeDirUser::class)
        ->where('grantable_id', $user->id)
        ->where('action', 'manage')
        ->exists()
    )->toBeTrue();
});

it('ShareDirectory is idempotent — duplicate grant does not throw', function () {
    $dir  = makeShareDir(name: 'docs');
    $user = makeFakeDirUser();

    (new ShareDirectory($dir, $user))->execute();
    (new ShareDirectory($dir, $user))->execute(); // second call — no exception

    expect(EntityPermission::where('entity_type', Directory::class)
        ->where('entity_id', $dir->id)
        ->where('grantable_id', $user->id)
        ->count()
    )->toBe(1);
});

it('RevokeDirectoryShare removes the grant', function () {
    $dir  = makeShareDir(name: 'docs');
    $user = makeFakeDirUser();

    (new ShareDirectory($dir, $user))->execute();
    (new RevokeDirectoryShare($dir, $user))->execute();

    expect(EntityPermission::where('entity_type', Directory::class)
        ->where('entity_id', $dir->id)
        ->where('grantable_id', $user->id)
        ->exists()
    )->toBeFalse();
});

it('RevokeDirectoryShare is a no-op when grant does not exist', function () {
    $dir  = makeShareDir(name: 'docs');
    $user = makeFakeDirUser();

    (new RevokeDirectoryShare($dir, $user))->execute(); // Should not throw

    expect(true)->toBeTrue();
});
