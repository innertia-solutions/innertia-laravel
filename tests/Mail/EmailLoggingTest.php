<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Auth\Mailables\ResetPasswordMail;
use Innertia\Mail\EmailLogging;
use Innertia\Mail\NotificationMail;
use Innertia\Platform\Models\EmailLog;

uses(RefreshDatabase::class);

beforeEach(function () {
    noticeSetUp();
    config()->set('mail.default', 'array');
    EmailLogging::listen();
});

it('every email gets a sent row with its type (account emails included, never unknown)', function () {
    Mail::to('ana@x.cl')->send(new ResetPasswordMail('Ana', 'https://app.test/r', 60));

    $log = EmailLog::first();
    expect($log->status)->toBe('sent')
        ->and($log->type)->toBe('account.password_reset')
        ->and($log->mailable_class)->toBe(ResetPasswordMail::class);
});

it('a queued row is marked sent when the email leaves', function () {
    $mail = NotificationMail::make()->type('voicemail.new')->withSubject('Hola')->title('T');
    EmailLogging::queued($mail, 'ana@x.cl', 'u1', tenantId: 't1');

    Mail::to('ana@x.cl')->send($mail);

    expect(EmailLog::count())->toBe(1);
    $log = EmailLog::first();
    expect($log->status)->toBe('sent')->and($log->type)->toBe('voicemail.new')->and($log->tenant_id)->toBe('t1');
});

it('a failed delivery is marked failed with the reason', function () {
    $mail = NotificationMail::make()->type('voicemail.new')->withSubject('Hola')->title('T');
    $mail->to('ana@x.cl');
    $log = EmailLogging::queued($mail, 'ana@x.cl');

    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn(['data' => [
        'commandName' => SendQueuedMailable::class,
        'command'     => serialize(new SendQueuedMailable($mail)),
    ]]);

    event(new JobFailed('redis', $job, new RuntimeException('Resend quota exceeded')));

    expect($log->fresh()->status)->toBe('failed')->and($log->fresh()->exception)->toBe('Resend quota exceeded');
});

it('the emails endpoint filters by type, tenant and status', function () {
    Route::get('emails-test', [\Innertia\Mail\Http\Controllers\EmailsController::class, 'index']);
    Route::get('emails-test/types', [\Innertia\Mail\Http\Controllers\EmailsController::class, 'types']);

    EmailLog::create(['to' => 'a@x.cl', 'subject' => 'A', 'mailable_class' => 'X', 'type' => 'voicemail.new', 'tenant_id' => 't1', 'status' => 'sent', 'created_at' => now()]);
    EmailLog::create(['to' => 'b@x.cl', 'subject' => 'B', 'mailable_class' => 'X', 'type' => 'account.otp', 'tenant_id' => null, 'status' => 'failed', 'created_at' => now()]);

    $this->getJson('/emails-test?type=voicemail.new')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.to', 'a@x.cl');
    $this->getJson('/emails-test?tenant_id=t1')->assertJsonPath('total', 1);
    $this->getJson('/emails-test?status=failed&search=b@x')->assertJsonPath('total', 1);
    $this->getJson('/emails-test/types')->assertJsonPath('data', ['account.otp', 'voicemail.new']);
});
