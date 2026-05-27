<?php

namespace Innertia\Files\Directories\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Directories\Events\DirectoryHardDeleted;
use Innertia\Files\Directories\Models\Directory;

class EmptyTrash
{
    public function __construct(
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): int
    {
        $count = 0;

        Directory::onlyTrashed()->chunkById(500, function ($batch) use (&$count) {
            foreach ($batch as $dir) {
                event(new DirectoryHardDeleted($dir->id, $dir->name, $this->performedBy));
                $dir->forceDelete();
                $count++;
            }
        });

        return $count;
    }
}
