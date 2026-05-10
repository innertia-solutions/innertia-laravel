<?php

namespace Innertia\Facades;

use Innertia\DataTable\DataTableService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Innertia\DataTable\DataTable create(string $name)
 *
 * @see \Innertia\DataTable\DataTableService
 */
class DataTable extends Facade
{
    protected static function getFacadeAccessor()
    {
        return DataTableService::class;
    }
}
