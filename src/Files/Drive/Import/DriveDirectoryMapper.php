<?php
namespace Innertia\Files\Drive\Import;

use Innertia\Files\Directories\Exceptions\DuplicateDirectoryNameException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\CreateDirectory;
use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Imports\Models\FileImport;

/**
 * Carpeta de Drive → Directory. Reimportar reutiliza la carpeta ya vinculada (provider +
 * external_id bajo el mismo padre). Si un hermano no vinculado ya usa el nombre, agrega
 * " (2)", " (3)"… (Drive permite nombres repetidos; Directories no).
 */
final class DriveDirectoryMapper
{
    public const PROVIDER = 'google_drive';
    private const MAX_SUFFIX = 50;

    public function ensure(DriveItem $folder, ?Directory $parent, FileImport $import): Directory
    {
        $existing = Directory::query()
            ->where('provider', self::PROVIDER)
            ->where('external_id', $folder->id)
            ->where('parent_id', $parent?->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $base = self::sanitize($folder->name);

        for ($n = 1; $n <= self::MAX_SUFFIX; $n++) {
            $name = $n === 1 ? $base : mb_substr($base, 0, 240) . " ({$n})";

            try {
                $dir = (new CreateDirectory(parent: $parent, name: $name))->execute();
                break;
            } catch (DuplicateDirectoryNameException) {
                $dir = null;
            }
        }

        if (! isset($dir) || $dir === null) {
            throw new \RuntimeException("No se pudo crear la carpeta \"{$base}\": demasiados nombres repetidos.");
        }

        $attrs = ['provider' => self::PROVIDER, 'external_id' => $folder->id, 'created_by' => $import->created_by];
        if ($parent === null) {
            // Los hijos heredan el dueño del padre (CreateDirectory); la raíz lo toma del import.
            $attrs += FileImport::filterAttributes($import->file_attributes ?? []);
        }
        $dir->forceFill($attrs)->save();

        return $dir;
    }

    public static function sanitize(string $name): string
    {
        $clean = trim(str_replace(['/', '\\'], '-', $name));

        return $clean === '' ? 'Sin nombre' : mb_substr($clean, 0, 255);
    }
}
