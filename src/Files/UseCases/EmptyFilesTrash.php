<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;

class EmptyFilesTrash
{
    public function __construct(
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): int
    {
        $count = 0;

        File::onlyTrashed()->chunkById(500, function ($batch) use (&$count) {
            foreach ($batch as $file) {
                $file->forceDelete();
                $count++;
            }
        });

        return $count;
    }
}
