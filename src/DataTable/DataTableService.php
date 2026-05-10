<?php

namespace Innertia\DataTable;

class DataTableService
{
    public static function create(string $name): DataTable
    {
        return new DataTable($name);
    }
}
