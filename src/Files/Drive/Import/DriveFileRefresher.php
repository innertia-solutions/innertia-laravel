<?php

namespace Innertia\Files\Drive\Import;

use Illuminate\Contracts\Auth\Authenticatable;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\ReplaceFileContents;

/**
 * Trae la revisión actual de Drive a un File importado.
 * - Misma revisión → 'unchanged' sin bajar nada (solo marca external_synced_at).
 * - Cambió → baja/exporta en el MISMO formato y pasa por ReplaceFileContents
 *   (que decide 'updated' o 'unchanged' por checksum). El nombre local no cambia.
 *
 * @return 'updated'|'unchanged'
 */
final class DriveFileRefresher
{
    public function __construct(private readonly DriveFileFetcher $fetcher) {}

    public function refresh(File $file, DriveItem $item, ?Authenticatable $performedBy = null, string $format = 'office'): string
    {
        $now = now();

        if ($item->revision() !== null && $item->revision() === $file->external_revision) {
            File::withTrashed()->whereKey($file->id)->toBase()->update(['external_synced_at' => $now]);
            $file->setAttribute('external_synced_at', $now);
            $file->syncOriginalAttribute('external_synced_at');

            return 'unchanged';
        }

        $fetched = $this->fetcher->fetch($item, $format, $item->isNative() ? $file->external_export_mime : null);

        try {
            $result = (new ReplaceFileContents(
                file:        $file,
                localPath:   $fetched->localPath,
                source:      FileVersionSource::ProviderRefresh,
                mimeType:    $fetched->mime,
                extension:   $fetched->extension,
                performedBy: $performedBy,
                meta:        [
                    'external_revision'    => $item->revision(),
                    'external_export_mime' => $fetched->exportMime,
                    'external_synced_at'   => $now,
                ],
            ))->execute();
        } finally {
            $fetched->delete();
        }

        return $result->wasCreated() ? 'updated' : 'unchanged';
    }
}
