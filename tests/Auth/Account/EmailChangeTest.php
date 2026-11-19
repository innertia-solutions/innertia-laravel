<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Auth\Mailables\EmailChangeCodeMail;
use Innertia\Auth\Mailables\EmailChangedMail;
use Innertia\Notifications\Models\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    noticeSetUp();
    Mail::fake();
    Route::middleware('web')->group(fn () => \Innertia\Auth\EmailChange\Routes::account('me/email-change'));
});

function requestCode($test, $user, string $email): string
{
    $test->actingAs($user)->postJson('/me/email-change', ['email' => $email, 'current_password' => 'Secreta123!'])->assertOk();

    $code = null;
    Mail::assertQueued(EmailChangeCodeMail::class, function ($m) use ($email, &$code) {
        $code = $m->code;

        return $m->hasTo($email);
    });

    return $code;
}

it('changes the email with the code and notifies the previous address', function () {
    $ana = noticeUser(['email' => 'ana@old.cl']);
    $code = requestCode($this, $ana, 'ana@new.cl');

    $this->actingAs($ana)->postJson('/me/email-change/confirm', ['code' => $code])->assertOk()->assertJsonPath('email', 'ana@new.cl');

    expect($ana->fresh()->email)->toBe('ana@new.cl');
    Mail::assertQueued(EmailChangedMail::class, fn ($m) => $m->hasTo('ana@old.cl'));
    expect(Notification::where('type', 'account.email_changed')->count())->toBe(1);
});

it('rejects an email already in use without saying where', function () {
    noticeUser(['email' => 'taken@x.cl']);
    $ana = noticeUser();

    $this->actingAs($ana)->postJson('/me/email-change', ['email' => 'TAKEN@x.cl', 'current_password' => 'Secreta123!'])
        ->assertStatus(422)->assertJsonPath('errors.email.0', 'Ese correo ya está en uso.');
});

it('requires the current password', function () {
    $this->actingAs(noticeUser())->postJson('/me/email-change', ['email' => 'n@x.cl', 'current_password' => 'mala'])
        ->assertStatus(422)->assertJsonValidationErrors('current_password');
});

it('a wrong code fails and after 5 attempts a new code is required', function () {
    $ana = noticeUser();
    $code = requestCode($this, $ana, 'ana@new.cl');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $_) {
        $this->actingAs($ana)->postJson('/me/email-change/confirm', ['code' => $wrong])->assertStatus(422)->assertJsonPath('errors.code.0', 'El código no es correcto.');
    }
    $this->actingAs($ana)->postJson('/me/email-change/confirm', ['code' => $wrong])->assertStatus(422)->assertJsonPath('errors.code.0', 'Demasiados intentos. Pide un código nuevo.');
    $this->actingAs($ana)->postJson('/me/email-change/confirm', ['code' => $code])->assertStatus(422)->assertJsonPath('errors.code.0', 'El código venció, pide uno nuevo.');
});

it('an expired code fails', function () {
    $ana = noticeUser();
    $code = requestCode($this, $ana, 'ana@new.cl');
    $this->travel(16)->minutes();

    $this->actingAs($ana)->postJson('/me/email-change/confirm', ['code' => $code])->assertStatus(422)->assertJsonPath('errors.code.0', 'El código venció, pide uno nuevo.');
});
