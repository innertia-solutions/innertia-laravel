<?php

namespace Innertia\Files\Support;

/**
 * Regla única para extensiones que terminan en una ruta de storage o en un
 * temporal local: minúsculas, sin punto inicial, solo [a-z0-9], máx. 20.
 */
final class FileExtension
{
    public static function normalize(?string $extension): ?string
    {
        $ext = strtolower((string) $extension);
        $ext = preg_replace('/[^a-z0-9]/', '', ltrim($ext, '.'));
        $ext = substr($ext, 0, 20);

        return $ext !== '' ? $ext : null;
    }
}
