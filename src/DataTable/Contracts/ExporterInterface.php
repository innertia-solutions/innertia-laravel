<?php

namespace Innertia\DataTable\Contracts;

use Illuminate\Http\Request;

interface ExporterInterface
{
    /**
     * Export the given data rows and return an HTTP response (download/stream).
     *
     * @param  array<int, array<string, mixed>>  $data         Rows resolved by DataTable
     * @param  Request                           $request      Original HTTP request
     * @param  array<int, string>               $columns      Column keys to include
     * @param  array<string, mixed>             $relationships Relationship config from DataTable
     * @param  string                           $name         DataTable name (used as filename)
     */
    public function export(
        array $data,
        Request $request,
        array $columns,
        array $relationships,
        string $name,
    ): mixed;
}
