<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\DataTable\DataTable;

class SdItem extends Model
{
    protected $table = 'sd_items';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    Schema::create('sd_items', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });
    SdItem::insert([['id' => 1, 'name' => 'b'], ['id' => 2, 'name' => 'a'], ['id' => 3, 'name' => 'c']]);
});

function sdTable(): DataTable
{
    return (new DataTable('sd_items'))->columns(['name'])->addCalculatedColumn('upper_name', 'UPPER(sd_items.name)');
}

it('la dirección de orden de una columna calculada no admite SQL arbitrario', function () {
    $sql = sdTable()
        ->getQuery(SdItem::class, '', [['column' => 'upper_name', 'direction' => 'asc, (SELECT 1); --']])
        ->toSql();

    expect($sql)->not->toContain('SELECT 1')->not->toContain('--');
});

it('respeta asc/desc válidos en columnas calculadas y normales (sin distinguir mayúsculas)', function () {
    $names = fn (array $sort) => sdTable()->getQuery(SdItem::class, '', [$sort])->pluck('name')->all();

    expect($names(['column' => 'upper_name', 'direction' => 'DESC']))->toBe(['c', 'b', 'a']);
    expect($names(['column' => 'upper_name', 'direction' => 'asc']))->toBe(['a', 'b', 'c']);
    expect($names(['column' => 'name', 'direction' => 'desc']))->toBe(['c', 'b', 'a']);
    expect($names(['column' => 'name', 'direction' => 'cualquier-cosa']))->toBe(['a', 'b', 'c']);
});
