<?php

namespace Innertia\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Innertia\Models\File;

/**
 * Allow any model to own files stored in the central files table.
 *
 * Usage:
 *   class Invoice extends Model
 *   {
 *       use HasFiles;
 *   }
 *
 *   $invoice->files              // all files owned by this invoice
 *   $invoice->attachFile($file)  // assign a File to this model
 *   $invoice->detachFile($file)  // remove ownership (file is not deleted)
 *   $invoice->files()->images()  // scope to images
 *   $invoice->files()->latest()->first()
 */
trait HasFiles
{
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'owner');
    }

    /**
     * Assign an existing File record to this model as its owner.
     */
    public function attachFile(File $file): File
    {
        $file->update([
            'owner_type' => static::class,
            'owner_id'   => (string) $this->getKey(),
        ]);

        return $file;
    }

    /**
     * Remove ownership from a file (does not delete the file from storage).
     */
    public function detachFile(File $file): void
    {
        if ($file->owner_type === static::class && $file->owner_id === (string) $this->getKey()) {
            $file->update(['owner_type' => null, 'owner_id' => null]);
        }
    }

    /**
     * Attach and immediately restrict the file to this model's access rules.
     * After this, $file->isAccessibleBy($user) cascades to $this->canAccess($user).
     */
    public function attachRestrictedFile(File $file): File
    {
        $this->attachFile($file);

        $file->update(['visibility' => 'restricted']);

        return $file;
    }
}
