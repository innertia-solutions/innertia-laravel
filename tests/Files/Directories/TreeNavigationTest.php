<?php

use Innertia\Files\Directories\Models\Directory;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaDirectoriesMigrateUp();

    // Tree: root / a / b / c
    $this->root = Directory::createIn(null, 'root');
    $this->a    = Directory::createIn($this->root, 'a');
    $this->b    = Directory::createIn($this->a, 'b');
    $this->c    = Directory::createIn($this->b, 'c');

    // Separate sibling tree: other
    $this->other = Directory::createIn(null, 'other');
});

afterEach(fn () => innertiaDirectoriesMigrateDown());

it('returns children directly', function () {
    expect($this->root->children->pluck('id')->all())->toEqual([$this->a->id]);
    expect($this->a->children->pluck('id')->all())->toEqual([$this->b->id]);
});

it('returns descendants via path LIKE', function () {
    $descendants = $this->root->descendants()->orderBy('depth')->get();

    expect($descendants->pluck('id')->all())->toEqual([
        $this->a->id, $this->b->id, $this->c->id,
    ]);
});

it('returns ancestors ordered root to immediate parent', function () {
    $ancestors = $this->c->ancestors();

    expect($ancestors->pluck('id')->all())->toEqual([
        $this->root->id, $this->a->id, $this->b->id,
    ]);
});

it('breadcrumbs include self at the end', function () {
    $crumbs = $this->c->breadcrumbs();

    expect($crumbs)->toHaveCount(4);
    expect($crumbs[0]['name'])->toBe('root');
    expect($crumbs[3]['name'])->toBe('c');
    expect($crumbs[3]['id'])->toBe($this->c->id);
});

it('isDescendantOf works in both directions', function () {
    expect($this->c->isDescendantOf($this->root))->toBeTrue();
    expect($this->c->isDescendantOf($this->a))->toBeTrue();
    expect($this->c->isDescendantOf($this->c))->toBeFalse();
    expect($this->c->isDescendantOf($this->other))->toBeFalse();
});

it('isAncestorOf is inverse of isDescendantOf', function () {
    expect($this->root->isAncestorOf($this->c))->toBeTrue();
    expect($this->c->isAncestorOf($this->root))->toBeFalse();
});

it('roots scope returns only top-level dirs', function () {
    $roots = Directory::roots()->pluck('id')->all();
    expect($roots)->toHaveCount(2);
    expect($roots)->toContain($this->root->id);
    expect($roots)->toContain($this->other->id);
});
