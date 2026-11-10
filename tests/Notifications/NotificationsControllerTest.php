<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Notifications\Models\Notification;
use Innertia\Notifications\Routes;

/**
 * Contrato del centro de notificaciones que consumen la campanita y la vista de
 * historial de innertia-nuxt (App.NotificationBell / App.NotificationHistory):
 *   GET    notifications            ?all=1&page=N&per_page=N → {data,total,unread,current_page,last_page,per_page}
 *   PATCH  notifications/{id}/read
 *   PATCH  notifications/read-all
 *   DELETE notifications/{id}
 *   DELETE notifications            (borra las leídas)
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Routes::register('notif-test');
    $this->user = new GenericUser(['id' => 'u1']);
});

function notif(string $userId, array $attrs = []): Notification
{
    static $i = 0;
    $i++;

    return Notification::create(array_merge([
        'user_id'    => $userId,
        'type'       => 'App\\Events\\Test',
        'key'        => 'test.event',
        'title'      => "Notificación {$i}",
        'body'       => 'Cuerpo',
        'data'       => ['link' => '/x'],
        'created_at' => now()->addSeconds($i),
    ], $attrs));
}

it('por defecto lista solo las no leídas del usuario autenticado', function () {
    notif('u1');
    notif('u1', ['read_at' => now()]);
    notif('u2');

    $this->actingAs($this->user)->getJson('/notif-test')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('unread', 1)
        ->assertJsonCount(1, 'data');
});

it('con all=1 incluye las leídas, ordenadas de la más nueva a la más vieja', function () {
    $old = notif('u1', ['read_at' => now()]);
    $new = notif('u1');

    $this->actingAs($this->user)->getJson('/notif-test?all=1')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id);
});

it('pagina con per_page y expone metadatos de página', function () {
    foreach (range(1, 5) as $_) notif('u1');

    $this->actingAs($this->user)->getJson('/notif-test?all=1&per_page=2&page=3')
        ->assertOk()
        ->assertJsonPath('per_page', 2)
        ->assertJsonPath('current_page', 3)
        ->assertJsonPath('last_page', 3)
        ->assertJsonCount(1, 'data');
});

it('acota per_page entre 1 y 100 (default 15)', function () {
    $this->actingAs($this->user)->getJson('/notif-test')->assertJsonPath('per_page', 15);
    $this->actingAs($this->user)->getJson('/notif-test?per_page=500')->assertJsonPath('per_page', 100);
    $this->actingAs($this->user)->getJson('/notif-test?per_page=0')->assertJsonPath('per_page', 1);
});

it('marca una como leída', function () {
    $n = notif('u1');

    $this->actingAs($this->user)->patchJson("/notif-test/{$n->id}/read")->assertOk();

    expect($n->fresh()->read_at)->not->toBeNull();
});

it('no permite marcar ni borrar notificaciones de otro usuario', function () {
    $other = notif('u2');

    $this->actingAs($this->user)->patchJson("/notif-test/{$other->id}/read")->assertNotFound();
    $this->actingAs($this->user)->deleteJson("/notif-test/{$other->id}")->assertNotFound();

    expect($other->fresh())->not->toBeNull()->and($other->fresh()->read_at)->toBeNull();
});

it('marca todas como leídas solo para el usuario', function () {
    notif('u1');
    notif('u1');
    $other = notif('u2');

    $this->actingAs($this->user)->patchJson('/notif-test/read-all')
        ->assertOk()->assertJsonPath('marked', 2);

    expect(Notification::where('user_id', 'u1')->whereNull('read_at')->count())->toBe(0)
        ->and($other->fresh()->read_at)->toBeNull();
});

it('elimina una notificación y borra todas las leídas', function () {
    $a = notif('u1');
    notif('u1', ['read_at' => now()]);
    notif('u1');

    $this->actingAs($this->user)->deleteJson("/notif-test/{$a->id}")->assertNoContent();
    $this->actingAs($this->user)->deleteJson('/notif-test')->assertOk()->assertJsonPath('deleted', 1);

    expect(Notification::where('user_id', 'u1')->count())->toBe(1);
});
