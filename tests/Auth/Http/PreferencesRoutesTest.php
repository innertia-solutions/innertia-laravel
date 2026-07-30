<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Traits\HasPreferences;

/**
 * Contrato que consume el frontend (useTablePreferences en innertia-nuxt ≥0.1.53):
 *   GET    auth/me/preferences/{key}   → { key, value }
 *   PUT    auth/me/preferences/{key}   → upsert  (body: value, cast)
 *   DELETE auth/me/preferences/{key}   → borrar
 *   GET    auth/me/preferences         → todas las públicas
 */
class PrefRoutesUser extends Model implements Authenticatable
{
    use HasPreferences;

    protected $table    = 'pref_routes_users';
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

    Schema::create('pref_routes_users', function ($t) {
        $t->string('id')->primary();
    });
    Schema::create('configs', function ($t) {
        $t->id();
        $t->string('owner_type');
        $t->string('owner_id');
        $t->string('type');
        $t->string('key');
        $t->text('value')->nullable();
        $t->string('cast')->default('string');
        $t->string('privacy')->default('private');
        $t->timestamps();
        $t->unique(['owner_type', 'owner_id', 'type', 'key']);
    });

    Route::middleware([])->group(function () {
        \Innertia\Auth\Routes::sessionRoutes();
    });
});

afterEach(function () {
    Schema::dropIfExists('configs');
    Schema::dropIfExists('pref_routes_users');
});

function makePrefUser(): PrefRoutesUser
{
    $u = new PrefRoutesUser;
    $u->id = (string) \Illuminate\Support\Str::uuid();
    $u->save();

    return $u;
}

it('persists a table preference via PUT auth/me/preferences/{key}', function () {
    $user = makePrefUser();
    $key  = 'table:users:columns';
    $columns = ['visibility' => ['email' => false], 'order' => ['name', 'roles']];

    $this->actingAs($user)
        ->putJson("/auth/me/preferences/{$key}", ['value' => $columns, 'cast' => 'json'])
        ->assertOk();

    expect($user->preference($key))->toEqual($columns);
});

it('reads a stored preference via GET auth/me/preferences/{key}', function () {
    $user = makePrefUser();
    $key  = 'table:users:columns';
    $columns = ['visibility' => ['email' => false]];
    $user->setPreference($key, $columns, 'json');

    $this->actingAs($user)
        ->getJson("/auth/me/preferences/{$key}")
        ->assertOk()
        ->assertJsonPath('value', $columns);
});

it('returns an empty value for an unset preference key', function () {
    $user = makePrefUser();

    $this->actingAs($user)
        ->getJson('/auth/me/preferences/table:missing:columns')
        ->assertOk()
        ->assertJsonPath('value', null);
});

it('deletes a preference via DELETE auth/me/preferences/{key}', function () {
    $user = makePrefUser();
    $key  = 'table:users:columns';
    $user->setPreference($key, ['x' => 1], 'json');

    $this->actingAs($user)
        ->deleteJson("/auth/me/preferences/{$key}")
        ->assertOk();

    expect($user->preference($key))->toBeNull();
});
