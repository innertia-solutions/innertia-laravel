<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\ErrorsController;
use Innertia\Telemetry\Models\TelemetryCase;
uses(RefreshDatabase::class);
it('index expone origin/category y filtra por ellos', function () {
    Route::get('t/errors', [ErrorsController::class, 'index']);
    TelemetryCase::create(['fingerprint'=>'a','title'=>'A','exception_class'=>'X','file'=>'/a','line'=>1,'message_normalized'=>'a','status'=>'open','times_seen'=>1,'first_seen'=>now(),'last_seen'=>now(),'last_trace_id'=>'t','origin'=>'queue','category'=>'database']);
    TelemetryCase::create(['fingerprint'=>'b','title'=>'B','exception_class'=>'X','file'=>'/b','line'=>1,'message_normalized'=>'b','status'=>'open','times_seen'=>1,'first_seen'=>now(),'last_seen'=>now(),'last_trace_id'=>'t','origin'=>'http','category'=>'runtime']);
    $all = $this->getJson('/t/errors')->assertOk()->json();
    $rows = $all['data'] ?? $all['rows'] ?? $all;
    expect(collect($rows)->pluck('origin')->sort()->values()->all())->toBe(['http', 'queue']);
    $filtered = $this->getJson('/t/errors?origin=queue')->assertOk()->json();
    $frows = $filtered['data'] ?? $filtered['rows'] ?? $filtered;
    expect(collect($frows)->pluck('origin')->unique()->values()->all())->toBe(['queue']);
});
