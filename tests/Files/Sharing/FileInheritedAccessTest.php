<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Models\File;

class FakeUser2 extends Model implements Authenticatable
{
    use HasUuids;
    protected $table = 'users_fake2';
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

function makeFile2(?Directory $dir = null, string $visibility = 'restricted'): File
{
    return File::create([
        'id'            => (string) \Illuminate\Support\Str::uuid(),
        'disk'          => 'local',
        'path'          => 'files/test/file.txt',
        'original_name' => 'file.txt',
        'size'          => 100,
        'visibility'    => $visibility,
        'created_by'    => (string) \Illuminate\Support\Str::uuid(),
        'directory_id'  => $dir?->id,
    ]);
}

function makeDir2(?Directory $parent = null, string $name = 'dir'): Directory
{
    return Directory::createIn($parent, $name);
}

function makeUser2(): FakeUser2
{
    $u = new FakeUser2;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    return $u;
}

it('restricted file is accessible to user with direct file grant', function () {
    $file = makeFile2();
    $user = makeUser2();

    $file->grantAccessTo($user, action: 'access');

    expect($file->isAccessibleBy($user))->toBeTrue();
});

it('restricted file is NOT accessible to user with no grants', function () {
    $file = makeFile2();
    $user = makeUser2();

    expect($file->isAccessibleBy($user))->toBeFalse();
});

it('restricted file is accessible via direct parent directory grant', function () {
    $dir  = makeDir2(name: 'docs');
    $file = makeFile2($dir);
    $user = makeUser2();

    EntityPermission::grant($dir, $user, 'view');

    expect($file->isAccessibleBy($user))->toBeTrue();
});

it('restricted file is accessible via ancestor directory grant (2 levels up)', function () {
    $root = makeDir2(name: 'root');
    $sub  = makeDir2($root, 'sub');
    $file = makeFile2($sub);
    $user = makeUser2();

    EntityPermission::grant($root, $user, 'view');

    expect($file->isAccessibleBy($user))->toBeTrue();
});

it('restricted file is NOT accessible via sibling directory grant', function () {
    $root    = makeDir2(name: 'root');
    $sibling = makeDir2($root, 'sibling');
    $target  = makeDir2($root, 'target');
    $file    = makeFile2($target);
    $user    = makeUser2();

    EntityPermission::grant($sibling, $user, 'view');

    expect($file->isAccessibleBy($user))->toBeFalse();
});

it('restricted file without directory_id is NOT accessible via directory grant', function () {
    $dir  = makeDir2(name: 'docs');
    $file = makeFile2(null); // no directory
    $user = makeUser2();

    EntityPermission::grant($dir, $user, 'view');

    expect($file->isAccessibleBy($user))->toBeFalse();
});

it('public file is always accessible', function () {
    $file = makeFile2(null, 'public');
    $user = makeUser2();

    expect($file->isAccessibleBy($user))->toBeTrue();
});

it('auth file is always accessible', function () {
    $file = makeFile2(null, 'auth');
    $user = makeUser2();

    expect($file->isAccessibleBy($user))->toBeTrue();
});

it('file creator always has access regardless of grants', function () {
    $userId = (string) \Illuminate\Support\Str::uuid();
    $user   = new FakeUser2;
    $user->id = $userId;

    $file = File::create([
        'id'            => (string) \Illuminate\Support\Str::uuid(),
        'disk'          => 'local',
        'path'          => 'files/test/file.txt',
        'original_name' => 'file.txt',
        'size'          => 100,
        'visibility'    => 'restricted',
        'created_by'    => $userId,
    ]);

    expect($file->isAccessibleBy($user))->toBeTrue();
});
