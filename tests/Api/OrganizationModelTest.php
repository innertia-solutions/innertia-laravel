<?php

use Illuminate\Support\Facades\Schema;
use Innertia\Api\Models\Organization;

beforeEach(function () {
    config()->set('innertia.mode', 'api');
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    Schema::create('organizations', function ($t) {
        $t->uuid('id')->primary();
        $t->uuid('parent_id')->nullable()->index();
        $t->string('name');
        $t->string('key')->unique();
        $t->string('status')->default('active');
        $t->timestamps();
        $t->softDeletes();

        $t->foreign('parent_id')
            ->references('id')
            ->on('organizations')
            ->onDelete('cascade');
    });
});

it('creates a root organization', function () {
    $org = Organization::create(['name' => 'Goberian', 'key' => 'goberian']);
    expect($org->id)->toBeString()
        ->and($org->isRoot())->toBeTrue()
        ->and($org->isActive())->toBeTrue();
});

it('creates a child organization under a parent', function () {
    $parent = Organization::create(['name' => 'Consultora', 'key' => 'consultora']);
    $child = Organization::create(['name' => 'Cliente A', 'key' => 'cliente-a', 'parent_id' => $parent->id]);

    expect($child->isChild())->toBeTrue()
        ->and($child->parent->id)->toBe($parent->id)
        ->and($parent->children->first()->id)->toBe($child->id);
});

it('ancestors() returns all parents up the tree', function () {
    $root = Organization::create(['name' => 'Root', 'key' => 'root']);
    $mid = Organization::create(['name' => 'Mid', 'key' => 'mid', 'parent_id' => $root->id]);
    $leaf = Organization::create(['name' => 'Leaf', 'key' => 'leaf', 'parent_id' => $mid->id]);

    $ancestors = $leaf->ancestors();
    expect($ancestors->pluck('id')->toArray())->toBe([$mid->id, $root->id]);
});

it('suspends and reactivates', function () {
    $org = Organization::create(['name' => 'Goberian', 'key' => 'goberian']);
    $org->suspend();
    expect($org->fresh()->isSuspended())->toBeTrue();
    $org->reactivate();
    expect($org->fresh()->isActive())->toBeTrue();
});

it('findByKey returns null for unknown key', function () {
    expect(Organization::findByKey('unknown'))->toBeNull();
});
