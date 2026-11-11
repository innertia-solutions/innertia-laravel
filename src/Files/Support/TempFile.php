<?php

namespace Innertia\Files\Support;

/** Temporales locales con nombre único (y extensión, para que mime_content_type acierte). */
final class TempFile
{
    public static function create(?string $extension = null): string
    {
        $ext = FileExtension::normalize($extension);
        $path = sys_get_temp_dir() . '/innertia-' . bin2hex(random_bytes(12)) . ($ext ? ".{$ext}" : '');

        if (@touch($path) === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal.');
        }

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
