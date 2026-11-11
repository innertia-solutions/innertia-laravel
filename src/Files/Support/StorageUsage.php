<?php

namespace Innertia\Files\Support;

use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;

/**
 * Bytes ocupados: vigentes + versiones superadas. Incluye archivos en papelera
 * (siguen ocupando storage). $scope recibe la query de File para acotar
 * (p. ej. por owner) y se aplica también a las versiones.
 */
final class StorageUsage
{
    /** @return array{current_bytes: int, versions_bytes: int, total_bytes: int} */
    public static function totals(?\Closure $scope = null): array
    {
        $files = File::withTrashed();
        if ($scope) {
            $scope($files);
        }

        $current = (int) (clone $files)->sum('size');

        $versions = FileVersioning::ready()
            ? (int) FileVersion::whereIn('file_id', (clone $files)->select('id'))->sum('size')
            : 0;

        return [
            'current_bytes'  => $current,
            'versions_bytes' => $versions,
            'total_bytes'    => $current + $versions,
        ];
    }
}
