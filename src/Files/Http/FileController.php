<?php

namespace Innertia\Files\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * GET /files/{file}
     * Stream the file inline (for images, PDFs viewed in browser).
     */
    public function view(Request $request, string $id): StreamedResponse
    {
        $file = File::findOrFail($id);

        $this->authorize($request, $file);

        // Inline solo para tipos que el browser renderiza de forma segura; los
        // riesgosos (HTML/SVG/etc.) se fuerzan a descarga para evitar que un
        // archivo subido ejecute script en el origen (XSS).
        return $this->stream($file, $this->inlineSafe($file) ? 'inline' : 'attachment');
    }

    /**
     * GET /files/{file}/download
     * Force-download the file as an attachment.
     */
    public function download(Request $request, string $id): StreamedResponse
    {
        $file = File::findOrFail($id);

        $this->authorize($request, $file);

        return $this->stream($file, 'attachment');
    }

    private function authorize(Request $request, File $file): void
    {
        if ($file->visibility === 'public') {
            return;
        }

        // Own-domain signed URL — the HMAC signature IS the credential, so the
        // file streams without a Bearer token (works in <img>/<iframe>/fetch).
        // Authorization was enforced when the signed URL was issued; expiry keeps
        // the leak window short. A present-but-invalid/expired signature is
        // rejected outright (403); no signature at all falls back to user auth.
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

        if ($file->visibility === 'auth') {
            return;
        }

        // restricted
        if (! $file->isAccessibleBy($user)) {
            abort(403, 'You do not have permission to access this file.');
        }
    }

    /**
     * ¿Es seguro renderizar este archivo inline en el browser? Los tipos que
     * pueden ejecutar script al renderizarse (HTML, SVG, XHTML) NUNCA van inline.
     */
    private function inlineSafe(File $file): bool
    {
        $mime = strtolower((string) $file->mime_type);
        $ext  = strtolower((string) $file->extension);

        // Peligrosos: pueden ejecutar script si se renderizan inline.
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

    private function stream(File $file, string $disposition): StreamedResponse
    {
        abort_unless(
            Storage::disk($file->disk)->exists($file->path),
            404,
            'File not found in storage.'
        );

        $size     = Storage::disk($file->disk)->size($file->path);
        $mimeType = $file->mime_type ?? 'application/octet-stream';
        $name     = $file->original_name;

        // Público → cacheable (apto CDN, firmas de correo, web); privado → sin caché.
        $cacheControl = $file->visibility === 'public'
            ? 'public, max-age=86400'
            : 'no-store, no-cache, private';

        return response()->stream(
            function () use ($file) {
                $stream = Storage::disk($file->disk)->readStream($file->path);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type'           => $mimeType,
                'Content-Length'         => $size,
                'Content-Disposition'    => "{$disposition}; filename=\"{$name}\"",
                'Cache-Control'          => $cacheControl,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
