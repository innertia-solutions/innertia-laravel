<?php

use Illuminate\Support\Facades\Schema;

pest()->group('org-enabled');

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);
    config()->set('innertia.organizations.tables', ['org_check_things']);
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    // Stub a model in tests/fixtures so the scan picks it up.
    $this->fixturesPath = sys_get_temp_dir() . '/innertia-check-fixtures';
    if (! is_dir($this->fixturesPath)) {
        mkdir($this->fixturesPath, 0777, true);
    }
    file_put_contents($this->fixturesPath . '/OrgCheckThing.php', <<<'PHP'
<?php
namespace OrgCheckFixtures;
use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Traits\HasOrganization;
class OrgCheckThing extends Model {
    use HasOrganization;
    protected $table = 'org_check_things';
}
PHP);
    require_once $this->fixturesPath . '/OrgCheckThing.php';
});

afterEach(function () {
    if (isset($this->fixturesPath)) {
        @unlink($this->fixturesPath . '/OrgCheckThing.php');
        @rmdir($this->fixturesPath);
    }
});

it('fails when the column is missing from the table', function () {
    Schema::create('org_check_things', fn ($t) => $t->id());
    $exit = $this->artisan('innertia:organization:check', [
        '--scan' => $this->fixturesPath,
    ])->run();
    expect($exit)->not->toBe(0);
});

it('passes when the table has the column and is declared in config', function () {
    Schema::create('org_check_things', function ($t) {
        $t->id();
        $t->unsignedBigInteger('organization_id')->nullable();
    });
    $this->artisan('innertia:organization:check', [
        '--scan' => $this->fixturesPath,
    ])->assertSuccessful();
});

it('fails when model uses trait but table is not in config', function () {
    config()->set('innertia.organizations.tables', []);
    Schema::create('org_check_things', function ($t) {
        $t->id();
        $t->unsignedBigInteger('organization_id')->nullable();
    });
    $exit = $this->artisan('innertia:organization:check', [
        '--scan' => $this->fixturesPath,
    ])->run();
    expect($exit)->not->toBe(0);
});
