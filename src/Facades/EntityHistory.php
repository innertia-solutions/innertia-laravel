<?php

namespace Innertia\Facades;

use Innertia\Services\EntityHistoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Innertia\Models\EntityHistory recordCreated(Model $model, ?string $reason = null)
 * @method static \Innertia\Models\EntityHistory recordUpdated(Model $model, ?string $reason = null)
 * @method static \Innertia\Models\EntityHistory recordDeleted(Model $model, ?string $reason = null)
 * @method static \Innertia\Models\EntityHistory recordRestored(Model $model, ?string $reason = null)
 * @method static \Innertia\Models\EntityHistory recordCustom(Model $model, string $action, ?array $changes = null, ?array $oldValues = null, ?array $newValues = null, ?string $reason = null)
 * @method static array getEntityHistory(string $entityType, string $entityId)
 * @method static array|null getEntityStateAt(string $entityType, string $entityId, $timestamp)
 * @method static array compareVersions(string $entityType, string $entityId, $timestamp1, $timestamp2)
 */
class EntityHistory extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EntityHistoryService::class;
    }
}
