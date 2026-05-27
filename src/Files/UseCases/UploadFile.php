<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Events\FileUploaded;
use Innertia\Files\Models\File;

class UploadFile
{
    public function __construct(
        public readonly UploadedFile $uploaded,
        public readonly ?string $directoryId = null,
        public readonly ?Model $owner = null,
        public readonly string $visibility = 'auth',
        public readonly ?string $disk = null,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): File
    {
        $disk = $this->disk ?: config('filesystems.default', 'local');
        $path = $this->uploaded->store('files/' . now()->format('Y/m'), $disk);

        $file = File::create([
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $this->uploaded->getClientOriginalName(),
            'mime_type'     => $this->uploaded->getMimeType(),
            'extension'     => strtolower($this->uploaded->getClientOriginalExtension()),
            'size'          => $this->uploaded->getSize(),
            'visibility'    => $this->visibility,
            'directory_id'  => $this->directoryId,
            'owner_type'    => $this->owner ? $this->owner::class : null,
            'owner_id'      => $this->owner?->getKey(),
            'created_by'    => $this->performedBy?->getAuthIdentifier(),
        ]);

        event(new FileUploaded($file, $this->performedBy));

        return $file;
    }
}
