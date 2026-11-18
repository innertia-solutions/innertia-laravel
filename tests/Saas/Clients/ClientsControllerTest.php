<?php

use Illuminate\Support\Facades\Route;
use Innertia\Saas\Clients\Routes;
use Innertia\Saas\Models\Client;
use Innertia\Saas\Models\Tenant;
use Innertia\Saas\UseCases\CreateClient;

beforeEach(function () {
    bootClientsMode($this->app);
    Route::prefix('t')->group(function () {
        Routes::read();
        Routes::write();
    });
});

it('returns the clients tree with spaces as children', function () {
    $res = $this->postJson('/t/clients/tree', ['search' => ''])->assertOk()->json();

    $node = collect($res['data'])->firstWhere('name', 'Rancagua');
    expect($node['type'])->toBe('client')
        ->and($node['has_children'])->toBeTrue()
        ->and($node['children'][0])->toMatchArray(['type' => 'space', 'key' => 'rancagua', 'environment' => 'production'])
        ->and($res['meta']['total_nodes'])->toBe(2);
});

it('filters the tree by client tax id or space name, case-insensitive', function () {
    (new CreateClient(name: 'Corporación Sur', attributes: ['tax_id' => '70.111.222-3']))->execute();

    $byTax = $this->postJson('/t/clients/tree', ['search' => '70.111'])->json('data');
    $bySpace = $this->postJson('/t/clients/tree', ['search' => 'RANCAG'])->json('data');

    expect(collect($byTax)->pluck('name')->all())->toBe(['Corporación Sur'])
        ->and(collect($bySpace)->pluck('name')->all())->toBe(['Rancagua']);
});

it('creates, shows and updates a client', function () {
    $id = $this->postJson('/t/clients', [
        'name' => 'Corporación Norte', 'tax_id' => '70.333.444-5', 'invoicing_mode' => 'per_space',
        'billing_email' => 'pagos@norte.cl',
    ])->assertCreated()->json('id');

    $this->getJson("/t/clients/{$id}")->assertOk()
        ->assertJsonPath('client.key', 'corporacion-norte')
        ->assertJsonPath('client.invoicing_mode', 'per_space')
        ->assertJsonPath('spaces', []);

    $this->patchJson("/t/clients/{$id}", ['legal_name' => 'Corporación Norte SpA'])->assertOk();
    expect(Client::find($id)->legal_name)->toBe('Corporación Norte SpA');
});

it('validates client input', function () {
    $this->postJson('/t/clients', ['name' => '', 'invoicing_mode' => 'monthly', 'billing_email' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'invoicing_mode', 'billing_email']);
});

it('changes a space environment', function () {
    $this->patchJson('/t/spaces/rancagua/environment', ['environment' => 'testing'])->assertOk()
        ->assertJsonPath('environment', 'testing');
    expect(Tenant::findByKey('rancagua')->isTesting())->toBeTrue();

    $this->patchJson('/t/spaces/rancagua/environment', ['environment' => 'staging'])->assertUnprocessable();
    $this->patchJson('/t/spaces/noexiste/environment', ['environment' => 'testing'])->assertNotFound();
});

it('rejects explicit nulls for non-nullable client columns', function () {
    $this->postJson('/t/clients', ['name' => 'Nulos', 'invoicing_mode' => null, 'grace_days' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invoicing_mode', 'grace_days']);

    $id = (new CreateClient(name: 'Estable'))->execute()->id;
    $this->patchJson("/t/clients/{$id}", ['payment_terms_days' => null, 'late_fee_percent' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_terms_days', 'late_fee_percent']);
});

it('escapes LIKE wildcards in the tree search', function () {
    $names = fn ($s) => collect($this->postJson('/t/clients/tree', ['search' => $s])->json('data'))->pluck('name')->all();

    expect($names('_'))->toBe([])
        ->and($names('%'))->toBe([]);
});
