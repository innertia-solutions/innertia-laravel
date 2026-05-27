<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Events\EventBusFake;
use Innertia\Platform\Organizations\Events\OrganizationEvent;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Platform\Organizations\UseCases\CreateOrganization;
use Innertia\Platform\Organizations\UseCases\DeleteOrganization;
use Innertia\Platform\Organizations\UseCases\UpdateOrganization;

pest()->group('org-enabled');

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);

    Schema::create('tenants', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('key')->unique();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('organizations', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('tenant_id')->index();
        $table->string('name');
        $table->string('key');
        $table->boolean('active')->default(true);
        $table->timestamps();
        $table->unique(['tenant_id', 'key']);
    });
});

afterEach(function () {
    Schema::dropIfExists('organizations');
    Schema::dropIfExists('tenants');
});

it('dispatches OrganizationCreated on create', function () {
    $fake = EventBusFake::fake();

    (new CreateOrganization(tenantId: 1, name: 'Acme', key: 'acme'))->execute();

    $fake->assertDispatched(OrganizationEvent::Created);
});

it('OrganizationCreated payload contains organization fields', function () {
    $fake = EventBusFake::fake();

    (new CreateOrganization(tenantId: 1, name: 'Acme', key: 'acme'))->execute();

    $fake->assertDispatched(OrganizationEvent::Created, function ($event) {
        $payload = $event->payload();
        return $payload['key'] === 'acme'
            && $payload['name'] === 'Acme'
            && $payload['active'] === true
            && isset($payload['organization_id']);
    });
});

it('dispatches OrganizationUpdated with old/new changes payload', function () {
    $org  = (new CreateOrganization(tenantId: 1, name: 'Acme', key: 'acme'))->execute();
    $fake = EventBusFake::fake();

    (new UpdateOrganization($org->id, name: 'Acme Corp'))->execute();

    $fake->assertDispatched(OrganizationEvent::Updated, function ($event) {
        return $event->changes['old']['name'] === 'Acme'
            && $event->changes['new']['name'] === 'Acme Corp';
    });
});

it('dispatches OrganizationDeleted with id, key and name', function () {
    $org  = (new CreateOrganization(tenantId: 1, name: 'Acme', key: 'acme'))->execute();
    $id   = $org->id;
    $fake = EventBusFake::fake();

    (new DeleteOrganization($org->id))->execute();

    $fake->assertDispatched(OrganizationEvent::Deleted, function ($event) use ($id) {
        $payload = $event->payload();
        return $payload['organization_id'] === $id
            && $payload['key'] === 'acme'
            && $payload['name'] === 'Acme';
    });
});
