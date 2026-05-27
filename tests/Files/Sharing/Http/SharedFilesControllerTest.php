<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Models\File;
use Innertia\Files\Routes as FileRoutes;

class FakeSharedUser extends Model implements Authenticatable
{
    use HasUuids;
    protected $table    = 'fake_shared_users';
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
    config()->set('innertia.mode', 'app');
    config()->set('innertia.directories.enabled', true);
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaShareMigrateUp();
    FileRoutes::register();
});

afterEach(fn () => innertiaShareMigrateDown());

function makeSharedFileUser(): FakeSharedUser
{
    $u = new FakeSharedUser;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    return $u;
}

function makeSharedTestFile(?Directory $dir = null, ?string $createdBy = null): File
{
    return File::create([
        'id'            => (string) \Illuminate\Support\Str::uuid(),
        'disk'          => 'local',
        'path'          => 'files/test/file.pdf',
        'original_name' => 'file.pdf',
        'size'          => 500,
        'visibility'    => 'restricted',
        'created_by'    => $createdBy ?? (string) \Illuminate\Support\Str::uuid(),
        'directory_id'  => $dir?->id,
    ]);
}

it('GET /files/shared-with-me returns files with direct grant', function () {
    $user = makeSharedFileUser();
    $file = makeSharedTestFile();

    EntityPermission::grant($file, $user, 'access');

    $response = $this->actingAs($user)->getJson('/files/shared-with-me');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($file->id);
});

it('GET /files/shared-with-me returns files via directory grant', function () {
    $user = makeSharedFileUser();
    $dir  = Directory::createIn(null, 'docs');
    $file = makeSharedTestFile($dir);

    EntityPermission::grant($dir, $user, 'access');

    $response = $this->actingAs($user)->getJson('/files/shared-with-me');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($file->id);
});

it('GET /files/shared-with-me excludes files created by the user', function () {
    $user    = makeSharedFileUser();
    $ownFile = makeSharedTestFile(null, $user->id);

    EntityPermission::grant($ownFile, $user, 'access');

    $response = $this->actingAs($user)->getJson('/files/shared-with-me');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($ownFile->id);
});

it('GET /files/shared-with-me excludes files with no grants', function () {
    $user = makeSharedFileUser();
    $file = makeSharedTestFile();

    $response = $this->actingAs($user)->getJson('/files/shared-with-me');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($file->id);
});

it('GET /files/shared-with-me returns files via ancestor directory grant', function () {
    $user  = makeSharedFileUser();
    $root  = Directory::createIn(null, 'root');
    $sub   = Directory::createIn($root, 'sub');
    $file  = makeSharedTestFile($sub);

    EntityPermission::grant($root, $user, 'access');

    $response = $this->actingAs($user)->getJson('/files/shared-with-me');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($file->id);
});
