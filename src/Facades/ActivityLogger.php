<?php

namespace Innertia\Facades;

use Innertia\Models\ActivityLog;
use Innertia\Services\ActivityLogService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ActivityLog log(string $action, ?string $entityType = null, ?string $entityId = null, ?string $userId = null, ?string $traceId = null, ?array $metadata = [], ?string $description = null)
 * @method static ActivityLog logUserAction(string $action, ?string $description = null, ?array $metadata = [])
 * @method static ActivityLog logEntityAction(string $action, string $entityType, string $entityId, ?string $description = null, ?array $metadata = [])
 * @method static ActivityLog logSecurityAction(string $action, ?string $description = null, ?array $metadata = [])
 */
class ActivityLogger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ActivityLogService::class;
    }
}
