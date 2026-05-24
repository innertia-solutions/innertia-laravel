<?php

use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;
use Innertia\Platform\Organizations\Middleware\RequireOrganization;

pest()->group('org-enabled');

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);
});

it('returns 400 when no organization is active', function () {
    $req  = Request::create('/foo', 'GET');
    $resp = (new RequireOrganization())->handle($req, fn () => new \Illuminate\Http\Response('ok'));
    expect($resp->getStatusCode())->toBe(400);
});

it('passes through when an organization is active', function () {
    Innertia::organization()->set(11);
    $req  = Request::create('/foo', 'GET');
    $resp = (new RequireOrganization())->handle($req, fn () => new \Illuminate\Http\Response('ok'));
    expect($resp)->toBeInstanceOf(\Illuminate\Http\Response::class);
    expect($resp->getContent())->toBe('ok');
});

it('is a no-op when feature disabled', function () {
    config()->set('innertia.organizations.enabled', false);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);
    $req  = Request::create('/foo', 'GET');
    $resp = (new RequireOrganization())->handle($req, fn () => new \Illuminate\Http\Response('ok'));
    expect($resp)->toBeInstanceOf(\Illuminate\Http\Response::class);
    expect($resp->getContent())->toBe('ok');
});
