<?php

namespace Innertia\Files\Drive;

/** Mapeo Google nativo → formato de exportación (config innertia.files.drive.native_exports). */
final class NativeExports
{
    /** @return array{mime: string, extension: string}|null  null = sin formato exportable */
    public static function target(string $nativeMime, string $format): ?array
    {
        $entry = self::formats($nativeMime)[$format] ?? null;

        return is_array($entry) && count($entry) === 2
            ? ['mime' => (string) $entry[0], 'extension' => (string) $entry[1]]
            : null;
    }

    /** Extensión de un mime de exportación ya usado (para refrescar en el mismo formato). */
    public static function extensionFor(string $nativeMime, string $exportMime): ?string
    {
        foreach (self::formats($nativeMime) as $entry) {
            if (is_array($entry) && ($entry[0] ?? null) === $exportMime) {
                return (string) $entry[1];
            }
        }

        return null;
    }

    /**
     * Formatos de un mime nativo. Se indexa el mapa a mano: los mimes llevan puntos
     * (vnd.google-apps.document) y romperían la notación de puntos de config().
     */
    private static function formats(string $nativeMime): array
    {
        $map = (array) config('innertia.files.drive.native_exports', []);

        return (array) ($map[$nativeMime] ?? []);
    }
}
