<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Route;
use Innertia\Mail\MailSettings;
use Innertia\Settings\AppSettingsService;

uses(RefreshDatabase::class);

beforeEach(function () {
    noticeSetUp();
    config()->set('mail.default', 'array');
});

it('saves settings, encrypts the Resend key and never returns it', function () {
    $show = MailSettings::save(['mailer' => 'resend', 'resend_api_key' => 're_secret', 'from_address' => 'no-reply@x.cl', 'brand_color' => '#123456']);

    expect($show['resend_api_key_set'])->toBeTrue()->and($show)->not->toHaveKey('resend_api_key');
    $raw = \Innertia\Settings\Models\Setting::where('key', 'mail.resend_api_key')->first();
    expect($raw->is_encrypted)->toBeTrue()->and($raw->getRawOriginal('value'))->not->toBe('re_secret');

    MailSettings::save(['resend_api_key' => MailSettings::KEY_UNCHANGED]);
    expect(MailSettings::stored()['resend_api_key'])->toBe('re_secret');
});

it('boot() applies the settings and re-applies them before every queued job', function () {
    MailSettings::save(['from_address' => 'uno@x.cl']);
    MailSettings::boot();
    expect(config('mail.from.address'))->toBe('uno@x.cl');

    (new AppSettingsService())->set('mail.from_address', 'dos@x.cl');
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    event(new JobProcessing('sync', $job));

    expect(config('mail.from.address'))->toBe('dos@x.cl');
});

it('mail-settings endpoints are owner only; the log is open to the platform team', function () {
    Route::middleware('web')->group(fn () => \Innertia\Mail\Routes::platform('p'));
    \Illuminate\Support\Facades\Schema::table('users', function ($t) {
        $t->boolean('is_platform_admin')->default(false);
        $t->string('platform_role')->nullable();
    });

    $staff = noticeUser(['is_platform_admin' => true, 'platform_role' => 'staff']);
    $owner = noticeUser(['is_platform_admin' => true, 'platform_role' => 'owner']);

    $this->actingAs($staff)->getJson('/p/mail-settings')->assertStatus(403)->assertJsonPath('code', 'platform_owner_required');
    $this->actingAs($staff)->getJson('/p/emails')->assertOk();

    $this->actingAs($owner)->putJson('/p/mail-settings', ['brand_color' => 'rojo'])
        ->assertStatus(422)->assertJsonValidationErrors('brand_color');
    $this->actingAs($owner)->putJson('/p/mail-settings', ['brand_color' => '#00ff00', 'from_name' => 'Plataforma'])
        ->assertOk()->assertJsonPath('brand_color', '#00ff00');
    $this->actingAs($owner)->deleteJson('/p/mail-settings/resend-key')->assertOk()->assertJsonPath('resend_api_key_set', false);

    $this->actingAs($owner)->postJson('/p/mail-settings/test', ['email' => 'qa@x.cl'])
        ->assertOk()->assertJsonPath('sent_to', 'qa@x.cl');
    expect(\Innertia\Platform\Models\EmailLog::where('type', 'mail.test')->exists())->toBeTrue();
});
