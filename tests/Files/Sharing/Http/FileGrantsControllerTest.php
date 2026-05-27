<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Models\File;
use Innertia\Files\Routes as FileRoutes;

class FakeFileGrantUser extends Model implements Authenticatable
{
    use HasUuids;
    protected $table    = 'fake_file_grant_users';
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
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaShareMigrateUp();
    FileRoutes::register();
});

afterEach(fn () => innertiaShareMigrateDown());

function makeGrantFile(): File
{
    return File::create([
        'id'            => (string) \Illuminate\Support\Str::uuid(),
        'disk'          => 'local',
        'path'          => 'files/test/doc.pdf',
        'original_name' => 'doc.pdf',
        'size'          => 1024,
        'visibility'    => 'restricted',
        'created_by'    => (string) \Illuminate\Support\Str::uuid(),
    ]);
}

function makeFileGrantUser(): FakeFileGrantUser
{
    $u = new FakeFileGrantUser;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    return $u;
}

it('POST /files/{id}/grants creates a grant', function () {
    $file = makeGrantFile();
    $user = makeFileGrantUser();

    $response = $this->postJson("/files/{$file->id}/grants", [
        'grantable_type'  => 'user',
        'grantable_id'    => $user->id,
        'grantable_class' => FakeFileGrantUser::class,
        'action'          => 'access',
    ]);

    $response->assertStatus(201)
             ->assertJsonStructure(['data' => ['id', 'entity_type', 'entity_id', 'action']]);

    expect(EntityPermission::where('entity_id', $file->id)->where('grantable_id', $user->id)->exists())->toBeTrue();
});

it('POST /files/{id}/grants returns 404 for unknown file', function () {
    $this->postJson('/files/nonexistent/grants', [
        'grantable_type'  => 'user',
        'grantable_id'    => (string) \Illuminate\Support\Str::uuid(),
        'grantable_class' => FakeFileGrantUser::class,
        'action'          => 'access',
    ])->assertStatus(404);
});

it('DELETE /files/{id}/grants revokes a grant', function () {
    $file = makeGrantFile();
    $user = makeFileGrantUser();

    EntityPermission::grant($file, $user, 'access');

    $this->deleteJson("/files/{$file->id}/grants", [
        'grantable_class' => FakeFileGrantUser::class,
        'grantable_id'    => $user->id,
        'action'          => 'access',
    ])->assertStatus(204);

    expect(EntityPermission::where('entity_id', $file->id)->where('grantable_id', $user->id)->exists())->toBeFalse();
});

it('GET /files/{id}/grants lists grants', function () {
    $file = makeGrantFile();
    $user = makeFileGrantUser();

    EntityPermission::grant($file, $user, 'access');

    $this->getJson("/files/{$file->id}/grants")
         ->assertStatus(200)
         ->assertJsonStructure(['data' => [['id', 'entity_id', 'action']]]);
});
