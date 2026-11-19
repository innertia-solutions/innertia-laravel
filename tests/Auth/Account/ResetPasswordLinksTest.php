<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Auth\Mailables\ResetPasswordMail;
use Innertia\Auth\Passwords\ResetPasswordLinks;

uses(RefreshDatabase::class);

beforeEach(fn () => noticeSetUp());
afterEach(function () {
    ResetPassword::$createUrlCallback = null;
    ResetPassword::$toMailCallback = null;
});

it('builds the front link and the branded reset email', function () {
    config()->set('innertia.auth.frontend_reset_path', '/portal/reset');
    ResetPasswordLinks::register();
    $ana = noticeUser();

    $mail = (new ResetPassword('tok123'))->toMail($ana);

    expect($mail)->toBeInstanceOf(ResetPasswordMail::class)
        ->and($mail->resetUrl)->toBe('https://app.test/portal/reset?token=tok123&email=' . urlencode($ana->email))
        ->and($mail->expiresInMinutes)->toBe(60)
        ->and($mail->emailType())->toBe('account.password_reset');
});
