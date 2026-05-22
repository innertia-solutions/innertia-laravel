<?php

namespace Innertia\Platform\Traits;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Innertia\Files\Models\File;

trait HasSingleFile
{
    /**
     * Get the single file associated with this model via polymorphic ownership.
     * Queries: SELECT * FROM files WHERE owner_type = ? AND owner_id = ?
     * Indexed via nullableMorphs('owner') in the files migration.
     */
    public function file(): MorphOne
    {
        return $this->morphOne(File::class, 'owner');
    }

    /**
     * Upload a file from a request field and associate it with this model.
     * Deletes the existing file (storage + DB row) if one exists.
     * Must be called inside DB::transaction() to avoid orphan files.
     */
    public function uploadFile(Request $request, string $field, string $disk = ''): File
    {
        $this->file?->delete();
        $file = File::fromRequest($request, $field, $disk);
        $file->update(['owner_type' => $this->getMorphClass(), 'owner_id' => $this->getKey()]);
        return $file;
    }

    /**
     * Replace the current file with a new UploadedFile instance.
     * Deletes the existing file if one exists.
     * Must be called inside DB::transaction() to avoid orphan files.
     */
    public function replaceFile(UploadedFile $uploaded, string $disk = ''): File
    {
        $this->file?->delete();
        $file = File::fromUploadedFile($uploaded, $disk);
        $file->update(['owner_type' => $this->getMorphClass(), 'owner_id' => $this->getKey()]);
        return $file;
    }

    /**
     * Delete the associated file from storage and the files table.
     * The model itself is NOT deleted.
     * Safe to call when no file is associated — no-ops silently.
     */
    public function deleteFile(): void
    {
        $this->file?->delete();
    }
}
