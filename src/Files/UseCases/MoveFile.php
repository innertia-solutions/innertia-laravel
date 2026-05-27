<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Directories\DirectoriesFeature;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Events\FileMoved;
use Innertia\Files\Exceptions\DirectoriesFeatureDisabledException;
use Innertia\Files\Models\File;

class MoveFile
{
    public function __construct(
        public readonly File $file,
        public readonly ?Directory $directory,   // null = root
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): File
    {
        if (! DirectoriesFeature::isActive()) {
            throw DirectoriesFeatureDisabledException::forFileMove();
        }

        $oldDirId = $this->file->directory_id;
        $newDirId = $this->directory?->id;

        $this->file->directory_id = $newDirId;
        $this->file->save();

        event(new FileMoved($this->file, $oldDirId, $newDirId, $this->performedBy));

        return $this->file;
    }
}
