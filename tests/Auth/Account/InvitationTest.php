<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Innertia\Auth\Invitations\InvitationBroker;
use Innertia\Auth\Invitations\UseCases\ResendInvitation;
use Innertia\Auth\Invitations\UseCases\SendInvitation;
use Innertia\Auth\Mailables\InvitationMail;

uses(RefreshDatabase::class);

beforeEach(function () {
    noticeSetUp();
    Mail::fake();
    Route::post('auth/password/reset', [\Innertia\Auth\Http\Controllers\PasswordController::class, 'reset']);
});

function tokenFrom(string $url): string
{
    parse_str(parse_url($url, PHP_URL_QUERY), $q);

    return $q['token'];
}

it('sends an invitation valid for 7 days with the platform brand', function () {
    $ana = noticeUser();

    $result = (new SendInvitation((string) $ana->id))->execute();

    expect($result['url'])->toStartWith('https://app.test/backoffice/auth/set-password?token=')
        ->and(now()->diffInDays(\Illuminate\Support\Carbon::parse($result['invitation_expires_at'])))->toBeGreaterThanOrEqual(6.9);

    Mail::assertQueued(InvitationMail::class, fn ($m) => $m->hasTo($ana->email) && $m->expiresInDays === 7);
    expect(\Innertia\Platform\Models\EmailLog::first()->type)->toBe('account.invitation');
});

it('resending invalidates the previous link', function () {
    $ana = noticeUser();
    $first = tokenFrom((new SendInvitation((string) $ana->id))->execute()['url']);
    $second = tokenFrom((new ResendInvitation((string) $ana->id))->execute()['url']);

    expect(InvitationBroker::check($ana->email, $first))->toBe(InvitationBroker::INVALID)
        ->and(InvitationBroker::check($ana->email, $second))->toBe(InvitationBroker::VALID);
});

it('throttles resends to 3 per hour', function () {
    $ana = noticeUser();

    foreach (range(1, 3) as $_) {
        (new ResendInvitation((string) $ana->id))->execute();
    }

    expect(fn () => (new ResendInvitation((string) $ana->id))->execute())->toThrow(ThrottleRequestsException::class);
});

it('the set-password page accepts the invitation token and consumes it', function () {
    $ana = noticeUser();
    $token = tokenFrom((new SendInvitation((string) $ana->id))->execute()['url']);

    $this->postJson('/auth/password/reset', [
        'token' => $token, 'email' => $ana->email, 'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123',
    ])->assertOk();

    expect(Hash::check('NuevaClave123', $ana->fresh()->password))->toBeTrue()
        ->and($ana->fresh()->email_verified_at)->not->toBeNull()
        ->and(InvitationBroker::status($ana))->toBeNull();
});

it('an expired invitation says the link expired', function () {
    $ana = noticeUser();
    $token = tokenFrom((new SendInvitation((string) $ana->id))->execute()['url']);
    $this->travel(8)->days();

    $this->postJson('/auth/password/reset', [
        'token' => $token, 'email' => $ana->email, 'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123',
    ])->assertStatus(422)->assertJsonPath('code', 'invitation_expired')->assertJsonPath('message', 'El link venció, pide uno nuevo.');
});

it('admin endpoints expose the status and resend', function () {
    Route::middleware('web')->group(fn () => \Innertia\Auth\Invitations\Routes::admin('inv-users'));
    $admin = noticeUser();
    $ana = noticeUser();

    $this->actingAs($admin)->getJson("/inv-users/{$ana->id}/invitation")->assertOk()->assertJsonPath('invited_at', null);
    $this->actingAs($admin)->postJson("/inv-users/{$ana->id}/invitation")->assertOk()->assertJsonPath('sent', true)->assertJsonMissingPath('url');
    $this->actingAs($admin)->getJson("/inv-users/{$ana->id}/invitation")->assertOk()->assertJsonPath('invitation_expired', false);
});
