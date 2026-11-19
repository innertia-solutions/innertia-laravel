<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Notifications\NoticeType;
use Innertia\Notifications\NoticeTypes;
use Innertia\Notifications\Routes;

uses(RefreshDatabase::class);

beforeEach(function () {
    noticeSetUp();
    NoticeTypes::register(new NoticeType('call.missed', 'Llamada perdida', channels: ['bell']));
    Routes::preferences('prefs-test');
});

it('lists every type with the effective state and locks critical ones', function () {
    $ana = noticeUser();

    $res = $this->actingAs($ana)->getJson('/prefs-test')->assertOk();
    $types = collect($res->json('data'))->keyBy('key');

    expect($types['call.missed']['preferences']['bell'])->toMatchArray(['enabled' => true, 'locked' => false])
        ->and($types['call.missed']['preferences']['mail'])->toMatchArray(['enabled' => false, 'locked' => false])
        ->and($types['account.password_changed']['critical'])->toBeTrue()
        ->and($types['account.password_changed']['preferences']['mail']['locked'])->toBeTrue();
});

it('saves preferences and ignores attempts to turn off critical types', function () {
    $ana = noticeUser();

    $res = $this->actingAs($ana)->putJson('/prefs-test', ['preferences' => [
        ['type' => 'call.missed', 'channel' => 'mail', 'enabled' => true],
        ['type' => 'account.password_changed', 'channel' => 'mail', 'enabled' => false],
    ]])->assertOk();

    $types = collect($res->json('data'))->keyBy('key');
    expect($types['call.missed']['preferences']['mail']['enabled'])->toBeTrue()
        ->and($types['account.password_changed']['preferences']['mail']['enabled'])->toBeTrue();
});

it('validates the channel', function () {
    $this->actingAs(noticeUser())->putJson('/prefs-test', ['preferences' => [
        ['type' => 'call.missed', 'channel' => 'sms', 'enabled' => true],
    ]])->assertStatus(422);
});
