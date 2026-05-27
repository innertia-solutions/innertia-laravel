<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\Models\Directory;

class FakeUser extends Model implements Authenticatable
{
    use HasUuids;
    protected $table = 'users_fake';
    public $timestamps = false;
    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthPassword(): string { return ''; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return 'remember_token'; }
    public function getAuthPasswordName(): string { return 'password'; }
}

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaShareMigrateUp();
});

afterEach(fn () => innertiaShareMigrateDown());

function makeSharedDir(?Directory $parent = null, string $name = 'dir'): Directory
{
    return Directory::createIn($parent, $name);
}

function makeSharedUser(): FakeUser
{
    $u = new FakeUser;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    return $u;
}

it('isAccessibleBy returns true when direct grant exists', function () {
    $dir  = makeSharedDir(name: 'docs');
    $user = makeSharedUser();

    EntityPermission::grant($dir, $user, 'view');

    expect($dir->isAccessibleBy($user))->toBeTrue();
});

it('isAccessibleBy returns false when no grant exists', function () {
    $dir  = makeSharedDir(name: 'docs');
    $user = makeSharedUser();

    expect($dir->isAccessibleBy($user))->toBeFalse();
});

it('isAccessibleBy returns true via ancestor grant', function () {
    $root = makeSharedDir(name: 'root');
    $sub  = makeSharedDir($root, 'sub');
    $user = makeSharedUser();

    EntityPermission::grant($root, $user, 'view');

    expect($sub->isAccessibleBy($user))->toBeTrue();
});

it('isAccessibleBy returns false when only sibling has grant', function () {
    $root    = makeSharedDir(name: 'root');
    $sibling = makeSharedDir($root, 'sibling');
    $target  = makeSharedDir($root, 'target');
    $user    = makeSharedUser();

    EntityPermission::grant($sibling, $user, 'view');

    expect($target->isAccessibleBy($user))->toBeFalse();
});

it('scopeAccessibleBy filters directories the user can access', function () {
    $root   = makeSharedDir(name: 'root');
    $child  = makeSharedDir($root, 'child');
    $other  = makeSharedDir(name: 'other');
    $user   = makeSharedUser();

    EntityPermission::grant($root, $user, 'view');

    $accessible = Directory::accessibleBy($user)->pluck('id')->all();

    expect($accessible)->toContain($root->id);
    expect($accessible)->toContain($child->id);
    expect($accessible)->not->toContain($other->id);
});

it('scopeAccessibleBy includes deeply nested descendants', function () {
    $root  = makeSharedDir(name: 'root');
    $lvl1  = makeSharedDir($root, 'lvl1');
    $lvl2  = makeSharedDir($lvl1, 'lvl2');
    $lvl3  = makeSharedDir($lvl2, 'lvl3');
    $user  = makeSharedUser();

    EntityPermission::grant($root, $user, 'view');

    $ids = Directory::accessibleBy($user)->pluck('id')->all();

    expect($ids)->toContain($lvl3->id);
});

it('HardDeleteDirectory cleans entity_permissions before forceDelete', function () {
    $dir  = makeSharedDir(name: 'docs');
    $user = makeSharedUser();

    EntityPermission::grant($dir, $user, 'view');
    expect(EntityPermission::where('entity_id', $dir->id)->count())->toBe(1);

    (new \Innertia\Files\Directories\UseCases\HardDeleteDirectory($dir))->execute();

    expect(EntityPermission::where('entity_id', $dir->id)->count())->toBe(0);
    expect(\Innertia\Files\Directories\Models\Directory::withTrashed()->find($dir->id))->toBeNull();
});
