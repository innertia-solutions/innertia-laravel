<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Innertia\Telemetry\Cases\CaseRecorder;
use Innertia\Telemetry\Mail\SupportCaseMail;

uses(RefreshDatabase::class);

// Helper: creates exceptions at a fixed call site so the fingerprinter
// sees the same file+line for all occurrences (same fingerprint).
function mailTestBoom(string $msg = 'User 1 not found'): \RuntimeException
{
    return new \RuntimeException($msg);
}

beforeEach(fn () => config()->set('innertia.telemetry.support_email', 'support@app.test'));

it('emails support once for a new case and not on recurrence', function () {
    Mail::fake();
    $recorder = app(CaseRecorder::class);

    $recorder->record(mailTestBoom('User 1 not found'), 'trace-1');
    $recorder->record(mailTestBoom('User 2 not found'), 'trace-2'); // mismo fingerprint

    Mail::assertSent(SupportCaseMail::class, 1);
});

it('does not email when support_email is not configured', function () {
    config()->set('innertia.telemetry.support_email', null);
    Mail::fake();

    app(CaseRecorder::class)->record(mailTestBoom('boom'), 'trace-1');

    Mail::assertNothingSent();
});

it('re-emails when a resolved case is reopened (nuevo episodio)', function () {
    Mail::fake();
    $recorder = app(CaseRecorder::class);

    $result = $recorder->record(mailTestBoom(), 'trace-1');   // nuevo → 1 correo
    $result['case']->update(['status' => 'resolved']);

    $recorder->record(mailTestBoom(), 'trace-2');             // reapertura → 2do correo

    Mail::assertSent(SupportCaseMail::class, 2);
});
