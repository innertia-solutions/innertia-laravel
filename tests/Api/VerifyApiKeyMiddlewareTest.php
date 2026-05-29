<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Innertia\Api\Middleware\VerifyApiKey;
use Innertia\Api\Models\ApiKey;
use Innertia\Api\Models\Organization;

beforeEach(function () {
    config()->set('innertia.mode', 'api');
    config()->set('innertia.api.key_prefix', 'cog_');
    config()->set('innertia.api.key_header', 'X-Api-Key');
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    Schema::create('organizations', function ($t) {
        $t->uuid('id')->primary();
        $t->uuid('parent_id')->nullable();
        $t->string('name');
        $t->string('key')->unique();
        $t->string('status')->default('active');
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create('api_keys', function ($t) {
        $t->uuid('id')->primary();
        $t->uuid('organization_id');
        $t->string('name')->nullable();
        $t->string('key')->unique();
        $t->string('key_prefix', 12)->index();
        $t->string('key_hint', 8);
        $t->boolean('is_default')->default(false);
        $t->timestamp('revoked_at')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamps();
    });
});

function makeOrgWithKey(): array
{
    $org = Organization::create(['name' => 'Goberian', 'key' => 'goberian']);
    ['raw' => $raw, 'attributes' => $attrs] = ApiKey::generate($org->id, 'Default', isDefault: true);
    $key = ApiKey::create(['organization_id' => $org->id, ...$attrs]);
    return [$org, $key, $raw];
}

it('passes request with valid key and injects organization + apiKey', function () {
    [$org, $key, $raw] = makeOrgWithKey();

    $req = Request::create('/test', 'GET', server: ['HTTP_X_API_KEY' => $raw]);
    $mw  = new VerifyApiKey();
    $resp = $mw->handle($req, fn ($r) => new Response('ok'));

    expect($resp->getContent())->toBe('ok')
        ->and($req->attributes->get('organization')->id)->toBe($org->id)
        ->and($req->attributes->get('api_key')->id)->toBe($key->id);
});

it('returns 401 when no key header', function () {
    $req  = Request::create('/test', 'GET');
    $resp = (new VerifyApiKey())->handle($req, fn ($r) => new Response('ok'));

    expect($resp->getStatusCode())->toBe(401);
});

it('returns 401 for invalid key', function () {
    $req  = Request::create('/test', 'GET', server: ['HTTP_X_API_KEY' => 'cog_badkey12345678901234567890123456789012']);
    $resp = (new VerifyApiKey())->handle($req, fn ($r) => new Response('ok'));

    expect($resp->getStatusCode())->toBe(401);
});

it('returns 403 when organization is suspended', function () {
    [$org, , $raw] = makeOrgWithKey();
    $org->suspend();

    $req  = Request::create('/test', 'GET', server: ['HTTP_X_API_KEY' => $raw]);
    $resp = (new VerifyApiKey())->handle($req, fn ($r) => new Response('ok'));

    expect($resp->getStatusCode())->toBe(403);
});

it('returns 401 for revoked key', function () {
    [$org, $key, $raw] = makeOrgWithKey();
    $key->revoke();

    $req  = Request::create('/test', 'GET', server: ['HTTP_X_API_KEY' => $raw]);
    $resp = (new VerifyApiKey())->handle($req, fn ($r) => new Response('ok'));

    expect($resp->getStatusCode())->toBe(401);
});
