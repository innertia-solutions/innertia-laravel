<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Innertia\Files\Events\FileRestored;
use Innertia\Files\Exceptions\OrphanedFileRestoreException;
use Innertia\Files\Models\File;

class RestoreFile
{
    public function __construct(
        public readonly File $file,
        public readonly ?string $relocateDirectoryId = null,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): File
    {
        if ($this->file->trash_group_id === null) {
            throw new \LogicException('File not in trash.');
        }

        // If file was in a directory and that dir is gone, require relocate
        $directoryClass = '\Innertia\Files\Directories\Models\Directory';
        if (Schema::hasColumn('files', 'directory_id') &&
            $this->file->directory_id !== null &&
            class_exists($directoryClass)) {

            $dirExists = $directoryClass::query()->whereKey($this->file->directory_id)->exists();
            if (! $dirExists) {
                if ($this->relocateDirectoryId === null) {
                    throw OrphanedFileRestoreException::for($this->file->id);
                }
                $this->file->directory_id = $this->relocateDirectoryId;
            }
        }

        DB::transaction(function () {
            $this->file->deleted_at     = null;
            $this->file->trash_group_id = null;
            $this->file->save();
        });

        event(new FileRestored(
            file: $this->file,
            relocatedToDirectoryId: $this->relocateDirectoryId,
            performedBy: $this->performedBy,
        ));

        return $this->file;
    }
}
