<?php

namespace Innertia\Files\Drive\Import;

use Innertia\Files\Drive\DriveSource;
use Innertia\Files\Drive\Exceptions\DriveImportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;

/**
 * Recorre la selección (BFS) sin bajar nada: así el límite de elementos se aplica antes de
 * escribir un solo byte. La clave de una carpeta es su id de Drive.
 */
final class DriveImportPlanner
{
    public function __construct(
        private readonly DriveSource $source,
        private readonly int $maxItems,
    ) {}

    /** @param list<string> $ids */
    public function plan(array $ids): DriveImportPlan
    {
        $plan = new DriveImportPlan();
        $seen = [];
        $queue = [];
        $rootFiles = [];

        foreach (array_unique($ids) as $id) {
            try {
                $item = $this->source->metadata($id);
            } catch (DriveItemNotFoundException) {
                $plan->missing[] = $id;
                continue;
            }

            if (isset($seen[$item->id])) {
                continue;
            }
            $seen[$item->id] = true;

            if ($item->isFolder()) {
                $plan->folders[] = ['item' => $item, 'parent_key' => null];
                $queue[] = $item;
            } else {
                $rootFiles[] = ['item' => $item, 'folder_key' => null];
            }
            $this->guard($plan, count($rootFiles));
        }

        while ($folder = array_shift($queue)) {
            foreach ($this->source->listChildren($folder->id, $folder->driveId) as $child) {
                if (isset($seen[$child->id])) {
                    continue;
                }
                $seen[$child->id] = true;

                if ($child->isFolder()) {
                    $plan->folders[] = ['item' => $child, 'parent_key' => $folder->id];
                    $queue[] = $child;
                } else {
                    $plan->files[] = ['item' => $child, 'folder_key' => $folder->id];
                }
                $this->guard($plan, count($rootFiles));
            }
        }

        // Los archivos sueltos de la selección van al final, tras el contenido de las carpetas.
        array_push($plan->files, ...$rootFiles);

        return $plan;
    }

    private function guard(DriveImportPlan $plan, int $pendingRootFiles): void
    {
        if (count($plan->files) + count($plan->folders) + $pendingRootFiles > $this->maxItems) {
            throw new DriveImportTooLargeException($this->maxItems);
        }
    }
}
