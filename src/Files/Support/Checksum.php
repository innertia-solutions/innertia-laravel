<?php

namespace Innertia\Files\Support;

use Illuminate\Support\Facades\Storage;

/** sha256 por streaming: nunca carga el archivo completo en memoria. */
final class Checksum
{
    public static function ofLocal(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new \RuntimeException("No se pudo leer {$path} para calcular su checksum.");
        }

        return $hash;
    }

    /** null si los bytes no existen en el disk. */
    public static function ofStored(string $disk, string $path): ?string
    {
        $fs = Storage::disk($disk);

        if (! $fs->exists($path)) {
            return null;
        }

        $stream = $fs->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);

            return hash_final($ctx);
        } finally {
            fclose($stream);
        }
    }
}
