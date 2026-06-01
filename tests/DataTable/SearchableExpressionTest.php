<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Innertia\DataTable\DataTable;

// ── Fixtures ─────────────────────────────────────────────────────────────────

class SePerson extends Model
{
    protected $table = 'se_people';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    Schema::create('se_people', function (Blueprint $t) {
        $t->id();
        $t->string('first_name');
        $t->string('last_name');
        $t->string('rut');
    });

    SePerson::insert([
        ['id' => 1, 'first_name' => 'Aaliyah', 'last_name' => 'Glover', 'rut' => '18261146-1'],
        ['id' => 2, 'first_name' => 'Abdiel',  'last_name' => 'Kassulke', 'rut' => '20604612-3'],
        ['id' => 3, 'first_name' => 'Abe',     'last_name' => 'Wolff', 'rut' => '9154764-3'],
    ]);
});

function renderSearch(DataTable $dt, string $term): array
{
    $req = Request::create('/', 'GET', ['search' => $term, 'perPage' => 50]);
    $res = $dt->render(SePerson::query(), $req);
    return json_decode($res->getContent(), true)['data'] ?? [];
}

// ── Tests ────────────────────────────────────────────────────────────────────

it('busca sobre una expresión concatenada (nombre completo + rut) vía addSearchableColumn', function () {
    $make = fn () => (new DataTable('se_people'))
        ->columns(['first_name', 'last_name'])
        ->addSearchableColumn('full', "first_name || ' ' || last_name || ' ' || rut");

    // por apellido
    expect(collect(renderSearch($make(), 'Glover'))->pluck('first_name')->all())->toBe(['Aaliyah']);
    // por rut (no es columna declarada en columns())
    expect(collect(renderSearch($make(), '20604612'))->pluck('first_name')->all())->toBe(['Abdiel']);
    // término sin match
    expect(renderSearch($make(), 'Zzz'))->toBe([]);
});

it('busca sobre una columna calculada marcada searchable=true', function () {
    $make = fn () => (new DataTable('se_people'))
        ->columns(['first_name'])
        ->addCalculatedColumn('"label"', "(first_name || ' ' || last_name)", true);

    expect(collect(renderSearch($make(), 'Kassulke'))->pluck('first_name')->all())->toBe(['Abdiel']);
});
