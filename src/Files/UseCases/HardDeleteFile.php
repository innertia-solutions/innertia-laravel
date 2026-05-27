<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Models\File;

class HardDeleteFile
{
    public function __construct(
        public readonly File $file,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): void
    {
        // File::forceDelete() already handles storage + event + DB
        $this->file->forceDelete();
    }
}
