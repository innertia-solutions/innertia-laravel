<?php

namespace Innertia\Platform\Traits;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Realtime\EntityChangeCollector;

/**
 * Emite cambios de la entidad por su TABLA física hacia el realtime, vía el
 * EntityChangeCollector (coalescido por request). Opt-in: agregar el trait al modelo.
 *
 * Personalización por modelo:
 *   - broadcastEntityResource(): string  → nombre de canal (default getTable()).
 *   - broadcastEntityPrivate(): bool      → canal privado-scopeado (default false).
 *   - $broadcastEntityDisabled (bool)     → apaga el broadcast para ese modelo.
 */
trait BroadcastsEntityChanges
{
    protected static function bootBroadcastsEntityChanges(): void
    {
        $emit = function (Model $model, string $action): void {
            if (($model->broadcastEntityDisabled ?? false) === true) {
                return;
            }
            app(EntityChangeCollector::class)->record(
                $model->broadcastEntityResource(),
                $action,
                $model->getKey(),
                $model->broadcastEntityPrivate(),
            );
        };

        static::created(fn (Model $m) => $emit($m, 'created'));
        static::updated(fn (Model $m) => $emit($m, 'updated'));
        static::deleted(fn (Model $m) => $emit($m, 'deleted'));

        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::restored(fn (Model $m) => $emit($m, 'restored'));
        }
    }

    public function broadcastEntityResource(): string
    {
        return $this->getTable();
    }

    public function broadcastEntityPrivate(): bool
    {
        return false;
    }
}
