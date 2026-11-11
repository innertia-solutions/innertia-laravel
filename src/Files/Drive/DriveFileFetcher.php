<?php

namespace Innertia\Files\Drive;

use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotImportableException;
use Innertia\Files\Support\FileExtension;

/**
 * Trae el contenido de un ítem de Drive a un temporal local: los binarios se bajan tal cual,
 * los nativos se exportan al formato pedido (o al ya usado, para refrescar igual).
 */
final class DriveFileFetcher
{
    public function __construct(private readonly DriveSource $source) {}

    /** @param string $format office|pdf */
    public function fetch(DriveItem $item, string $format, ?string $forcedExportMime = null): FetchedDriveFile
    {
        if ($item->isFolder()) {
            throw new DriveItemNotImportableException('Es una carpeta.', 'folder');
        }
        if ($item->isShortcut()) {
            throw DriveItemNotImportableException::shortcut();
        }

        return $item->isNative()
            ? $this->exportNative($item, $format, $forcedExportMime)
            : $this->downloadBinary($item);
    }

    private function downloadBinary(DriveItem $item): FetchedDriveFile
    {
        $max = (int) config('innertia.files.drive.max_file_size', 200 * 1024 * 1024);

        if ($item->size !== null && $item->size > $max) {
            throw new DriveFileTooLargeException($max);
        }

        return new FetchedDriveFile(
            localPath:  $this->source->download($item->id, $max),
            name:       $item->name,
            mime:       $item->mime,
            extension:  FileExtension::normalize(pathinfo($item->name, PATHINFO_EXTENSION)),
            exportMime: null,
        );
    }

    private function exportNative(DriveItem $item, string $format, ?string $forcedExportMime): FetchedDriveFile
    {
        $target = $forcedExportMime !== null
            ? (($ext = NativeExports::extensionFor($item->mime, $forcedExportMime)) !== null ? ['mime' => $forcedExportMime, 'extension' => $ext] : null)
            : NativeExports::target($item->mime, $format);

        if ($target === null) {
            throw DriveItemNotImportableException::noExport();
        }

        $suffix = '.' . $target['extension'];
        $name = str_ends_with(strtolower($item->name), $suffix) ? $item->name : $item->name . $suffix;

        return new FetchedDriveFile(
            localPath:  $this->source->export($item->id, $target['mime']),
            name:       $name,
            mime:       $target['mime'],
            extension:  $target['extension'],
            exportMime: $target['mime'],
        );
    }
}
