<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Innertia\Platform\Events\EventBusFake;
use Innertia\Tags\Events\TagEvent;
use Innertia\Tags\Traits\HasTags;
use Innertia\Tags\UseCases\AttachTags;
use Innertia\Tags\UseCases\CreateTag;
use Innertia\Tags\UseCases\DeleteTag;
use Innertia\Tags\UseCases\DetachTags;
use Innertia\Tags\UseCases\SyncTags;
use Innertia\Tags\UseCases\UpdateTag;

class EventsQuote extends Model {
    use HasUuids, HasTags;
    protected $table = 'events_quotes';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');

    require_once __DIR__ . '/helpers/migrate.php';
    innertiaTagsMigrateUp();

    Schema::create('events_quotes', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->string('title');
    });
});

afterEach(function () {
    Schema::dropIfExists('events_quotes');
    innertiaTagsMigrateDown();
});

it('dispatches TagCreated on create', function () {
    $fake = EventBusFake::fake();

    (new CreateTag('Urgente'))->execute();

    $fake->assertDispatched(TagEvent::Created);
});

it('TagCreated payload contains tag fields', function () {
    $fake = EventBusFake::fake();

    (new CreateTag('Urgente', '#ff0000'))->execute();

    $fake->assertDispatched(TagEvent::Created, function ($event) {
        $payload = $event->payload();
        return $payload['name'] === 'Urgente'
            && $payload['slug'] === 'urgente'
            && $payload['color'] === '#ff0000';
    });
});

it('dispatches TagUpdated on update with changes payload', function () {
    $tag  = (new CreateTag('Urgente'))->execute();
    $fake = EventBusFake::fake();

    (new UpdateTag($tag->id, name: 'Critical'))->execute();

    $fake->assertDispatched(TagEvent::Updated, function ($event) {
        return $event->changes['old']['name'] === 'Urgente'
            && $event->changes['new']['name'] === 'Critical';
    });
});

it('dispatches TagDeleted on delete', function () {
    $tag  = (new CreateTag('Urgente'))->execute();
    $fake = EventBusFake::fake();

    (new DeleteTag($tag->id))->execute();

    $fake->assertDispatched(TagEvent::Deleted);
});

it('TagDeleted payload contains tag id and slug', function () {
    $tag  = (new CreateTag('Urgente'))->execute();
    $id   = $tag->id;
    $fake = EventBusFake::fake();

    (new DeleteTag($tag->id))->execute();

    $fake->assertDispatched(TagEvent::Deleted, function ($event) use ($id) {
        $payload = $event->payload();
        return $payload['tag_id'] === $id
            && $payload['slug'] === 'urgente';
    });
});

it('dispatches TagsAttached on attach', function () {
    $quote = EventsQuote::create(['title' => 'Q1']);
    $fake  = EventBusFake::fake();

    (new AttachTags(entity: $quote, names: ['vip', 'urgente']))->execute();

    $fake->assertDispatched(TagEvent::Attached, function ($event) use ($quote) {
        $payload = $event->payload();
        return $payload['entity_id'] === $quote->id
            && in_array('vip', $payload['slugs'])
            && in_array('urgente', $payload['slugs']);
    });
});

it('dispatches TagsDetached on detach', function () {
    $quote = EventsQuote::create(['title' => 'Q1']);
    $quote->tag('vip', 'urgente');
    $fake  = EventBusFake::fake();

    (new DetachTags(entity: $quote, names: ['vip']))->execute();

    $fake->assertDispatched(TagEvent::Detached, function ($event) use ($quote) {
        $payload = $event->payload();
        return $payload['entity_id'] === $quote->id
            && in_array('vip', $payload['slugs']);
    });
});

it('dispatches TagsSynced with added and removed arrays', function () {
    $quote = EventsQuote::create(['title' => 'Q1']);
    $quote->tag('vip', 'urgente');
    $fake  = EventBusFake::fake();

    (new SyncTags(entity: $quote, names: ['nuevo', 'urgente']))->execute();

    $fake->assertDispatched(TagEvent::Synced, function ($event) {
        $payload = $event->payload();
        return in_array('nuevo', $payload['added'])
            && in_array('vip', $payload['removed'])
            && ! in_array('urgente', $payload['removed']);
    });
});
