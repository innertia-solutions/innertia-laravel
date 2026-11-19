<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Innertia\Mail\NotificationMail;
use Innertia\Notifications\Models\Notification;
use Innertia\Notifications\Models\NoticeState;
use Innertia\Notifications\Notice;
use Innertia\Notifications\NoticeType;
use Innertia\Notifications\NoticeTypes;
use Innertia\Notifications\Notifier;
use Innertia\Notifications\Preferences;
use Innertia\Platform\Models\EmailLog;

uses(RefreshDatabase::class);

beforeEach(function () {
    noticeSetUp();
    Mail::fake();
    NoticeTypes::register(
        new NoticeType('voicemail.new', 'Mensaje de voz', channels: ['bell', 'mail']),
        new NoticeType('call.missed', 'Llamada perdida', channels: ['bell'], group: true, groupTitle: ':count llamadas perdidas'),
        new NoticeType('agenda.sync_failed', 'Agenda', channels: ['bell', 'mail'], debounceMinutes: 30),
    );
});

it('delivers bell and mail on the default channels with a queued email log', function () {
    $ana = noticeUser();

    $stats = Notifier::sendNow(new Notice(type: 'voicemail.new', title: 'Nuevo mensaje de voz', body: 'Anexo 1203', url: '/backoffice/voicemail/1', recipients: [$ana]));

    expect($stats)->toMatchArray(['bell' => 1, 'mail' => 1]);
    $n = Notification::where('user_id', $ana->id)->first();
    expect($n->title)->toBe('Nuevo mensaje de voz')->and($n->data['url'])->toBe('/backoffice/voicemail/1');

    Mail::assertQueued(NotificationMail::class, function (NotificationMail $m) use ($ana) {
        return $m->hasTo($ana->email) && $m->emailType() === 'voicemail.new' && $m->tries === 3;
    });

    $log = EmailLog::first();
    expect($log->status)->toBe('queued')->and($log->type)->toBe('voicemail.new')->and($log->user_id)->toBe((string) $ana->id);
});

it('dedupes recipients', function () {
    $ana = noticeUser();

    Notifier::sendNow(new Notice(type: 'voicemail.new', title: 'X', recipients: [$ana, $ana->id, (string) $ana->id]));

    expect(Notification::count())->toBe(1);
    Mail::assertQueued(NotificationMail::class, 1);
});

it('respects user preferences per channel', function () {
    $ana = noticeUser();
    Preferences::save((string) $ana->id, [['type' => 'voicemail.new', 'channel' => 'mail', 'enabled' => false]]);

    Notifier::sendNow(new Notice(type: 'voicemail.new', title: 'X', recipients: [$ana]));

    expect(Notification::count())->toBe(1);
    Mail::assertNothingQueued();
});

it('critical types ignore preferences and cannot be turned off', function () {
    $ana = noticeUser();
    $written = Preferences::save((string) $ana->id, [['type' => NoticeTypes::ACCOUNT_PASSWORD_CHANGED, 'channel' => 'mail', 'enabled' => false]]);

    expect($written)->toBe(0);
    Notifier::sendNow(new Notice(type: NoticeTypes::ACCOUNT_PASSWORD_CHANGED, title: 'Cambio', recipients: [$ana]));

    Mail::assertQueued(NotificationMail::class, 1);
});

it('debounces repeats of the same type and subject', function () {
    $ana = noticeUser();
    $notice = fn () => new Notice(type: 'agenda.sync_failed', title: 'Falló la agenda', recipients: [$ana], subjectKey: 'agenda:1');

    Notifier::sendNow($notice());
    $second = Notifier::sendNow($notice());

    expect($second['skipped'])->toBe(1)->and(Notification::count())->toBe(1);

    $this->travel(31)->minutes();
    Notifier::sendNow($notice());
    expect(Notification::count())->toBe(2);
});

it('groups repeats into one bell item with a counter', function () {
    $ana = noticeUser();

    foreach (range(1, 3) as $i) {
        Notifier::sendNow(new Notice(type: 'call.missed', title: 'Llamada perdida', body: "De 9{$i}", recipients: [$ana]));
    }

    expect(Notification::count())->toBe(1);
    $n = Notification::first();
    expect($n->title)->toBe('3 llamadas perdidas')->and($n->data['count'])->toBe(3);
    Mail::assertNothingQueued();
});

it('resolve closes the open notice and the next one is delivered again', function () {
    $ana = noticeUser();
    $notice = fn () => new Notice(type: 'agenda.sync_failed', title: 'Central sin conexión', recipients: [$ana], subjectKey: 'central:1');

    Notifier::sendNow($notice());
    expect(Notifier::resolve('agenda.sync_failed', 'central:1'))->toBe(1);
    expect(Notification::first()->read_at)->not->toBeNull()
        ->and(NoticeState::first()->resolved_at)->not->toBeNull();

    Notifier::sendNow($notice());
    expect(Notification::count())->toBe(2);
});

it('send() delivers through the queue (sync driver) and keeps the trace id', function () {
    $ana = noticeUser();
    app()->instance('trace_id', 'trace-abc');

    Notifier::send(new Notice(type: 'voicemail.new', title: 'Cola', recipients: [$ana]));

    expect(Notification::first()->data['trace_id'])->toBe('trace-abc')
        ->and(EmailLog::first()->trace_id)->toBe('trace-abc');
});

it('without email only the bell is delivered', function () {
    $ana = noticeUser();
    $ana->forceFill(['email' => ''])->saveQuietly();

    Notifier::sendNow(new Notice(type: 'voicemail.new', title: 'X', recipients: [$ana->id]));

    expect(Notification::count())->toBe(1);
    Mail::assertNothingQueued();
});

it('recipients by permission are resolved generically', function () {
    $ana = noticeUser();
    $bob = noticeUser(['name' => 'Bob']);
    $ana->givePermission('phone.manage');

    $ids = \Innertia\Notifications\Recipients::withPermission('phone.manage')->pluck('id')->all();

    expect($ids)->toBe([$ana->id]);
});
