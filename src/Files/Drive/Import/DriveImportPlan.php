<?php

namespace Innertia\Files\Drive\Import;

use Innertia\Files\Drive\DriveItem;

/** Resultado del recorrido: carpetas (padres antes que hijos), archivos y ids inexistentes. */
final class DriveImportPlan
{
    /** @var list<array{item: DriveItem, parent_key: ?string}> */
    public array $folders = [];

    /** @var list<array{item: DriveItem, folder_key: ?string}> */
    public array $files = [];

    /** @var list<string> */
    public array $missing = [];

    public function fileCount(): int
    {
        return count($this->files);
    }
}
