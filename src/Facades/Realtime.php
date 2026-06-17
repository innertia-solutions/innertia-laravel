<?php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\Platform\Realtime\EntityChangeCollector;

/**
 * @method static void touch(string $table, array $ids = [], string $action = 'updated', bool $private = false)
 * @method static void record(string $table, string $action, mixed $id, bool $private = false)
 * @method static void flush()
 *
 * @see EntityChangeCollector
 */
class Realtime extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EntityChangeCollector::class;
    }
}
