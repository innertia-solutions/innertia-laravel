<?php

namespace Innertia\Files\Drive;

use Innertia\Files\Support\TempFile;

/** Un ítem de Drive ya bajado/exportado a un temporal local. El llamador llama delete(). */
final class FetchedDriveFile
{
    public function __construct(
        public readonly string $localPath,
        public readonly string $name,
        public readonly string $mime,
        public readonly ?string $extension,
        public readonly ?string $exportMime,
    ) {}

    public function delete(): void
    {
        TempFile::delete($this->localPath);
    }
}
