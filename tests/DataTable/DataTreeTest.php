<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Innertia\DataTable\DataTree;

// ── Test fixture model ───────────────────────────────────────────────────────

class TreeNode extends Model
{
    protected $table = 'tree_nodes';

    protected $guarded = [];

    public $timestamps = false;
}

// ── Setup: sqlite in-memory + tabla con jerarquía ────────────────────────────

beforeEach(function () {
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);

    Schema::create('tree_nodes', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('code')->nullable();
        $t->unsignedBigInteger('parent_id')->nullable();
    });

    // Jerarquía de prueba:
    //   1 Root A
    //     2 A.1
    //       4 A.1.1
    //         7 A.1.1.1   ← nivel 3, fuera del default maxDepth=3
    //     3 A.2
    //   5 Root B
    //     6 B.1
    //   8 Root C (sin hijos)
    TreeNode::insert([
        ['id' => 1, 'name' => 'Root A',    'code' => 'A',     'parent_id' => null],
        ['id' => 2, 'name' => 'A.1',       'code' => 'A1',    'parent_id' => 1],
        ['id' => 3, 'name' => 'A.2',       'code' => 'A2',    'parent_id' => 1],
        ['id' => 4, 'name' => 'A.1.1',     'code' => 'A11',   'parent_id' => 2],
        ['id' => 5, 'name' => 'Root B',    'code' => 'B',     'parent_id' => null],
        ['id' => 6, 'name' => 'B.1',       'code' => 'B1',    'parent_id' => 5],
        ['id' => 7, 'name' => 'A.1.1.1',   'code' => 'A111',  'parent_id' => 4],
        ['id' => 8, 'name' => 'Root C',    'code' => 'C',     'parent_id' => null],
    ]);
});

// ── Initial load (no expand) ─────────────────────────────────────────────────

it('returns roots with descendants up to maxDepth-1', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name', 'code'])
        ->maxDepth(3);

    $response = $tree->render(new Request)->getData(true);
    $data = $response['data'];

    expect($data)->toHaveCount(3); // 3 roots: A, B, C

    // Root A debe tener children
    $rootA = collect($data)->firstWhere('id', 1);
    expect($rootA['depth'])->toBe(0);
    expect($rootA['has_children'])->toBeTrue();
    expect($rootA['children'])->toHaveCount(2);

    // A.1 está en depth 1, con A.1.1 en depth 2
    $a1 = collect($rootA['children'])->firstWhere('id', 2);
    expect($a1['depth'])->toBe(1);
    expect($a1['has_children'])->toBeTrue();
    expect($a1['children'])->toHaveCount(1);

    // A.1.1 está en depth 2 — último nivel: NO debe traer `children` (lazy)
    $a11 = $a1['children'][0];
    expect($a11['id'])->toBe(4);
    expect($a11['depth'])->toBe(2);
    expect($a11['has_children'])->toBeTrue();   // tiene A.1.1.1 (id=7) en BD
    expect($a11)->not->toHaveKey('children');   // lazy: el frontend debe pedir expand=4
});

it('marks leaf nodes with has_children=false', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(3);

    $response = $tree->render(new Request)->getData(true);
    $rootC = collect($response['data'])->firstWhere('id', 8);

    expect($rootC['has_children'])->toBeFalse();
    expect($rootC['children'])->toBe([]);
});

// ── Expand (lazy load) ───────────────────────────────────────────────────────

it('lazy-loads a subtree when expand param is provided', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(3);

    // Expandir nodo 4 (A.1.1) — debe traer A.1.1.1 como root del subárbol
    $response = $tree->render(new Request(['expand' => 4]))->getData(true);
    $data = $response['data'];

    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe(7);
    expect($data[0]['name'])->toBe('A.1.1.1');
    expect($data[0]['depth'])->toBe(0);          // depth se resetea desde el ancla
    expect($data[0]['has_children'])->toBeFalse();
});

it('returns empty when expanding a node with no children', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(3);

    $response = $tree->render(new Request(['expand' => 8]))->getData(true);
    expect($response['data'])->toBe([]);
});

// ── Search (v1: solo en ancla) ───────────────────────────────────────────────

it('filters roots by search term', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(3);

    $response = $tree->render(new Request(['search' => 'Root A']))->getData(true);
    $data = $response['data'];

    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe(1);
    expect($data[0]['children'])->not->toBeEmpty(); // hijos vienen completos
});

// ── prepareQuery callback ────────────────────────────────────────────────────

it('honors prepareQuery callback for filtering', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name', 'code'])
        ->maxDepth(2)
        ->prepareQuery(function ($q) {
            $q->where('code', 'A');  // solo Root A
        });

    $response = $tree->render(new Request)->getData(true);
    expect($response['data'])->toHaveCount(1);
    expect($response['data'][0]['code'])->toBe('A');
});

// ── maxDepth ─────────────────────────────────────────────────────────────────

it('respects maxDepth=1 (only roots, no descendants)', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(1);

    $response = $tree->render(new Request)->getData(true);
    $data = $response['data'];

    // 3 roots
    expect($data)->toHaveCount(3);
    foreach ($data as $node) {
        expect($node['depth'])->toBe(0);
        expect($node)->not->toHaveKey('children'); // depth alcanzó max → lazy
        // Root A y B tienen hijos en la BD
        if (in_array($node['id'], [1, 5])) {
            expect($node['has_children'])->toBeTrue();
        }
    }
});

it('respects maxDepth=2 (roots + 1 level)', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(2);

    $response = $tree->render(new Request)->getData(true);
    $rootA = collect($response['data'])->firstWhere('id', 1);

    expect($rootA['children'])->toHaveCount(2);   // A.1 y A.2
    $a1 = collect($rootA['children'])->firstWhere('id', 2);
    expect($a1)->not->toHaveKey('children');       // se cortó en depth=1
    expect($a1['has_children'])->toBeTrue();       // pero tiene hijos en BD
});

// ── has_children batch (sin N+1) ────────────────────────────────────────────

it('detects has_children without N+1 (single GROUP BY query)', function () {
    $tree = DataTree::create('nodes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(3);

    \DB::enableQueryLog();
    $tree->render(new Request);
    $queries = \DB::getQueryLog();
    \DB::disableQueryLog();

    // Esperado: 1 query anchor IDs + 1 CTE + 1 has_children GROUP BY = 3 queries totales
    // (Tolerancia: ≤ 4 por si el connection.begin/commit aparece)
    expect(count($queries))->toBeLessThanOrEqual(4);

    // Verificar que hay exactamente 1 query con GROUP BY sobre parent_id
    $groupByCount = collect($queries)->filter(fn ($q) =>
        str_contains(strtolower($q['query']), 'group by') &&
        str_contains(strtolower($q['query']), 'parent_id')
    )->count();
    expect($groupByCount)->toBe(1);
});

// ── Validations ──────────────────────────────────────────────────────────────

it('rejects models that are not Eloquent', function () {
    expect(fn () => DataTree::create('x', \stdClass::class))
        ->toThrow(\InvalidArgumentException::class);
});

it('rejects maxDepth < 1', function () {
    expect(fn () => DataTree::create('nodes', TreeNode::class)->maxDepth(0))
        ->toThrow(\InvalidArgumentException::class);
});

// ── Response meta ────────────────────────────────────────────────────────────

it('includes correct meta in response', function () {
    $tree = DataTree::create('sedes', TreeNode::class)
        ->columns(['name'])
        ->maxDepth(2);

    $response = $tree->render(new Request)->getData(true);
    expect($response['meta']['table_name'])->toBe('sedes');
    expect($response['meta']['parent_column'])->toBe('parent_id');
    expect($response['meta']['max_depth'])->toBe(2);
    expect($response['meta']['expand'])->toBeNull();
    expect($response['meta']['total_nodes'])->toBeInt();
});
