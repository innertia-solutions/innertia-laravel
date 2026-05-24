<?php

use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;
use Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader;

class FakeOrganization implements \Innertia\Platform\Contracts\OrganizationContract
{
    public function __construct(public int $id, public string $key, public ?int $tenantId = 1) {}
    public function getKey() { return $this->id; }
    public function getRouteKey() { return $this->key; }
    public function getTenantId(): int|string|null { return $this->tenantId; }
    public static function findByKey(string $key): ?static {
        return match ($key) {
            'acme' => new self(10, 'acme'),
            'beta' => new self(20, 'beta'),
            default => null,
        };
    }
}

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);
    config()->set('innertia.organizations.model', FakeOrganization::class);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);
});

it('is a no-op when feature is disabled', function () {
    config()->set('innertia.organizations.enabled', false);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);

    $req = Request::create('/foo', 'GET', server: ['HTTP_X_ORGANIZATION' => 'acme']);
    $mw  = new ResolveOrganizationFromHeader();
    $mw->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    expect(Innertia::organization())->toBeNull();
});

it('resolves org by key from X-Organization and sets current + scope', function () {
    $req = Request::create('/foo', 'GET', server: ['HTTP_X_ORGANIZATION' => 'acme']);
    $mw  = new ResolveOrganizationFromHeader();
    $mw->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    expect(Innertia::organization()->current())->toBe(10);
    expect(Innertia::organization()->scope())->toBe([10]);
});

it('returns 401 when X-Organization header refers to unknown slug', function () {
    $req = Request::create('/foo', 'GET', server: ['HTTP_X_ORGANIZATION' => 'ghost']);
    $mw  = new ResolveOrganizationFromHeader();
    $resp = $mw->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    expect($resp->getStatusCode())->toBe(401);
    // Context must remain untouched when the slug doesn't resolve.
    expect(Innertia::organization()->current())->toBeNull();
    expect(Innertia::organization()->scope())->toBe([]);
});

it('passes through when no header present', function () {
    $req = Request::create('/foo', 'GET');
    $mw  = new ResolveOrganizationFromHeader();
    $mw->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    expect(Innertia::organization()->current())->toBeNull();
});

it('honours X-Consolidated:true by leaving current() and asking RBAC for the user scope', function () {
    // Simulated user provides accessibleOrganizationIds()
    $user = new class implements \Illuminate\Contracts\Auth\Authenticatable {
        public function accessibleOrganizationIds(): array { return [10, 20, 30]; }
        public function getAuthIdentifierName() { return 'id'; }
        public function getAuthIdentifier() { return 1; }
        public function getAuthPasswordName() { return 'password'; }
        public function getAuthPassword() { return ''; }
        public function getRememberToken() { return ''; }
        public function setRememberToken($value) {}
        public function getRememberTokenName() { return ''; }
    };
    auth()->setUser($user);

    $req = Request::create('/foo', 'GET', server: [
        'HTTP_X_ORGANIZATION' => 'acme',
        'HTTP_X_CONSOLIDATED' => 'true',
    ]);
    $mw = new ResolveOrganizationFromHeader();
    $mw->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    expect(Innertia::organization()->current())->toBe(10);
    expect(Innertia::organization()->scope())->toBe([10, 20, 30]);
    expect(Innertia::organization()->inConsolidatedView())->toBeTrue();
});

it('returns 500 when configured model does not implement OrganizationContract', function () {
    config()->set('innertia.organizations.model', \stdClass::class);

    $req = Request::create('/foo', 'GET', server: ['HTTP_X_ORGANIZATION' => 'acme']);
    $mw  = new ResolveOrganizationFromHeader();
    $resp = $mw->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    expect($resp->getStatusCode())->toBe(500);
});
