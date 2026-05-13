<?php

namespace Innertia\Platform\Traits;

use Innertia\Facades\EntityHistory;
use Illuminate\Database\Eloquent\Model;

trait HasHistory
{
    protected static function bootHasHistory(): void
    {
        static::created(function (Model $model) {
            EntityHistory::recordCreated($model, static::getChangeReason($model, 'created'));
        });

        static::updated(function (Model $model) {
            if (! empty($model->getChanges())) {
                EntityHistory::recordUpdated($model, static::getChangeReason($model, 'updated'));
            }
        });

        static::deleted(function (Model $model) {
            EntityHistory::recordDeleted($model, static::getChangeReason($model, 'deleted'));
        });

        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::restored(function (Model $model) {
                EntityHistory::recordRestored($model, static::getChangeReason($model, 'restored'));
            });
        }
    }

    protected static function getChangeReason(Model $model, string $action): ?string
    {
        if (request()->has('change_reason')) {
            return request()->get('change_reason');
        }

        return match ($action) {
            'created' => 'Entity created',
            'updated' => 'Entity updated',
            'deleted' => 'Entity deleted',
            'restored' => 'Entity restored',
            default => null,
        };
    }

    public function getHistory(): array
    {
        return EntityHistory::getEntityHistory(
            get_class($this),
            $this->getKey()
        );
    }

    public function getStateAt($timestamp): ?array
    {
        return EntityHistory::getEntityStateAt(
            get_class($this),
            $this->getKey(),
            $timestamp
        );
    }

    public function compareAt($timestamp1, $timestamp2): array
    {
        return EntityHistory::compareVersions(
            get_class($this),
            $this->getKey(),
            $timestamp1,
            $timestamp2
        );
    }

    public function recordCustomChange(
        string $action,
        ?array $changes = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null
    ): \App\Platform\Models\EntityHistory {
        return EntityHistory::recordCustom(
            $this,
            $action,
            $changes,
            $oldValues,
            $newValues,
            $reason
        );
    }
}
