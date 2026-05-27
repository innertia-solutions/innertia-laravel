<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\RevokeFileShare;
use Innertia\Files\UseCases\ShareFile;

class FakeFileUser extends Model implements Authenticatable
{
    use HasUuids;
    protected $table = 'fake_file_users';
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
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaShareMigrateUp();
});

afterEach(fn () => innertiaShareMigrateDown());

function makeShareFile(): File
{
    return File::create([
        'id'            => (string) \Illuminate\Support\Str::uuid(),
        'disk'          => 'local',
        'path'          => 'files/test/report.pdf',
        'original_name' => 'report.pdf',
        'size'          => 1024,
        'visibility'    => 'restricted',
        'created_by'    => (string) \Illuminate\Support\Str::uuid(),
    ]);
}

function makeFakeFileUser(): FakeFileUser
{
    $u = new FakeFileUser;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    return $u;
}

it('ShareFile creates a view grant by default', function () {
    $file = makeShareFile();
    $user = makeFakeFileUser();

    (new ShareFile($file, $user))->execute();

    expect(EntityPermission::where('entity_type', File::class)
        ->where('entity_id', $file->id)
        ->where('grantable_type', FakeFileUser::class)
        ->where('grantable_id', $user->id)
        ->where('action', 'view')
        ->exists()
    )->toBeTrue();
});

it('ShareFile accepts custom action', function () {
    $file = makeShareFile();
    $user = makeFakeFileUser();

    (new ShareFile($file, $user, 'edit'))->execute();

    expect(EntityPermission::where('entity_type', File::class)
        ->where('entity_id', $file->id)
        ->where('grantable_id', $user->id)
        ->where('action', 'edit')
        ->exists()
    )->toBeTrue();
});

it('ShareFile is idempotent', function () {
    $file = makeShareFile();
    $user = makeFakeFileUser();

    (new ShareFile($file, $user))->execute();
    (new ShareFile($file, $user))->execute();

    expect(EntityPermission::where('entity_type', File::class)
        ->where('entity_id', $file->id)
        ->where('grantable_id', $user->id)
        ->count()
    )->toBe(1);
});

it('RevokeFileShare removes the grant', function () {
    $file = makeShareFile();
    $user = makeFakeFileUser();

    (new ShareFile($file, $user))->execute();
    (new RevokeFileShare($file, $user))->execute();

    expect(EntityPermission::where('entity_type', File::class)
        ->where('entity_id', $file->id)
        ->where('grantable_id', $user->id)
        ->exists()
    )->toBeFalse();
});

it('RevokeFileShare is a no-op when grant does not exist', function () {
    $file = makeShareFile();
    $user = makeFakeFileUser();

    (new RevokeFileShare($file, $user))->execute(); // Should not throw

    expect(true)->toBeTrue();
});

it('file becomes accessible after ShareFile is called', function () {
    $file = makeShareFile();
    $user = makeFakeFileUser();

    expect($file->isAccessibleBy($user))->toBeFalse();

    (new ShareFile($file, $user, 'access'))->execute();

    expect($file->fresh()->isAccessibleBy($user))->toBeTrue();
});
