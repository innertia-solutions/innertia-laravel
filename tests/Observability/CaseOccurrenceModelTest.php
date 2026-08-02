<?php
use Innertia\Telemetry\Models\TelemetryCaseOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('upsert incrementa el conteo diario por caso', function () {
    $day = '2026-08-02';
    TelemetryCaseOccurrence::query()->upsert(
        [['case_id' => 'c1', 'day' => $day, 'count' => 1]],
        ['case_id', 'day'],
        ['count' => \Illuminate\Support\Facades\DB::raw('"count" + 1')]
    );
    TelemetryCaseOccurrence::query()->upsert(
        [['case_id' => 'c1', 'day' => $day, 'count' => 1]],
        ['case_id', 'day'],
        ['count' => \Illuminate\Support\Facades\DB::raw('"count" + 1')]
    );
    $row = TelemetryCaseOccurrence::where('case_id', 'c1')->where('day', $day)->first();
    expect((int) $row->count)->toBe(2);
});
