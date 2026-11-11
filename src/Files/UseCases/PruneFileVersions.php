<?php

namespace Innertia\Files\UseCases;

use Innertia\Files\Models\File;

class PruneFileVersions
{
    public function __construct(
        public readonly File $file,
        public readonly ?int $keep = null,
    ) {}

    /** @return int versiones eliminadas */
    public function execute(): int
    {
        return 0; // Task 4
    }
}
