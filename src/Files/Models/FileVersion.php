<?php

namespace Innertia\Files\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Snapshot inmutable de una versión SUPERADA de un File. La versión vigente vive
 * en la fila `files`. Nunca se edita: solo se crea (al reemplazar) o se borra
 * (retención / borrado definitivo del archivo).
 *
 * @property string $id
 * @property string $file_id
 * @property int    $version
 */
class FileVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'file_versions';

    protected $fillable = [
        'file_id', 'version', 'disk', 'path', 'original_name', 'mime_type', 'extension', 'size',
        'checksum', 'source', 'restored_from_version', 'external_revision', 'external_export_mime',
        'created_by', 'created_at', 'superseded_at',
    ];

    protected $casts = [
        'version'               => 'integer',
        'size'                  => 'integer',
        'restored_from_version' => 'integer',
        'created_at'            => 'datetime',
        'superseded_at'         => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id')->withTrashed();
    }

    /** Path relativo firmado de descarga (mismo esquema que File::signedDownloadPath). */
    public function signedDownloadPath(?int $minutes = null): string
    {
        return URL::temporarySignedRoute(
            'innertia.files.versions.download',
            now()->addMinutes($minutes ?? (int) config('innertia.files.url_ttl', 15)),
            ['id' => $this->file_id, 'version' => $this->version],
            absolute: false,
        );
    }
}
