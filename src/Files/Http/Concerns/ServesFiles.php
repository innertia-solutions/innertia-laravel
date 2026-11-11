<?php

namespace Innertia\Files\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Autorización + streaming seguro compartidos por el serving del archivo vigente
 * y el de sus versiones. Una versión hereda el acceso de su File.
 */
trait ServesFiles
{
    /**
     * $treatPublicAs: visibilidad efectiva si el archivo es `public` (p. ej. 'auth'
     * para versiones superadas: el historial de un archivo público no es público).
     * null = sin cambio.
     */
    protected function authorizeFile(Request $request, File $file, ?string $treatPublicAs = null): void
    {
        $visibility = $file->visibility === 'public' && $treatPublicAs !== null ? $treatPublicAs : $file->visibility;

        if ($visibility === 'public') {
            return;
        }

        // Own-domain signed URL — the HMAC signature IS the credential, so the
        // file streams without a Bearer token (works in <img>/<iframe>/fetch).
        // A present-but-invalid/expired signature is rejected outright (403);
        // no signature at all falls back to user auth.
        if ($request->hasValidSignature(absolute: false)) {
            return;
        }

        if ($request->query('signature') !== null) {
            abort(403, 'Invalid or expired signature.');
        }

        $user = $request->user();

        if (! $user) {
            abort(401, 'Authentication required.');
        }

        if ($visibility === 'auth') {
            return;
        }

        if (! $file->isAccessibleBy($user)) {
            abort(403, 'You do not have permission to access this file.');
        }
    }

    /**
     * ¿Es seguro renderizar inline? HTML/SVG/XHTML nunca van inline (XSS).
     */
    protected function inlineSafe(?string $mime, ?string $extension): bool
    {
        $mime = strtolower((string) $mime);
        $ext  = strtolower((string) $extension);

        if ($mime === 'image/svg+xml' || $ext === 'svg') {
            return false;
        }
        if ($mime === 'text/html' || $mime === 'application/xhtml+xml' || in_array($ext, ['html', 'htm', 'xhtml'], true)) {
            return false;
        }

        return str_starts_with($mime, 'image/')
            || $mime === 'application/pdf'
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || $mime === 'text/plain';
    }

    protected function streamStored(
        string $disk,
        string $path,
        ?string $mimeType,
        string $name,
        string $visibility,
        string $disposition,
    ): StreamedResponse {
        abort_unless(Storage::disk($disk)->exists($path), 404, 'File not found in storage.');

        $size = Storage::disk($disk)->size($path);
        $safeName = str_replace(['"', "\r", "\n"], '', $name);

        // Público → cacheable (apto CDN, firmas de correo, web); privado → sin caché.
        $cacheControl = $visibility === 'public' ? 'public, max-age=86400' : 'no-store, no-cache, private';

        return response()->stream(
            function () use ($disk, $path) {
                $stream = Storage::disk($disk)->readStream($path);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type'           => $mimeType ?? 'application/octet-stream',
                'Content-Length'         => $size,
                'Content-Disposition'    => "{$disposition}; filename=\"{$safeName}\"",
                'Cache-Control'          => $cacheControl,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
