<?php

namespace Innertia\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Innertia\Traits\HasEntityPermissions;

/**
 * Central file registry for all uploaded/generated files.
 *
 * Usage:
 *   $file = File::fromRequest($request, 'avatar');
 *   $file = File::fromPath('/abs/path/report.csv');
 *   $file = File::fromUrl('https://example.com/data.xlsx');
 *   $file = File::fromUploadedFile($uploadedFile);
 *
 *   $file->url()           // → /files/{id}/download (goes through permission check)
 *   $file->viewUrl()       // → /files/{id}/view    (inline, permission check)
 *   $file->temporaryUrl()  // → signed cloud URL    (bypasses permission check)
 *
 * Visibility:
 *   public     — anyone can access (even unauthenticated)
 *   auth       — any authenticated user (default)
 *   restricted — explicit grants required (see allowUsers / allowRoles)
 *
 * Restricted access (entity-level permissions):
 *   $file->allowUsers($user1, $user2)
 *   $file->allowRoles('admin', 'manager')
 *   $file->restrict(users: [$user1], roles: ['admin'])
 *   $file->isAccessibleBy($user)
 *
 * @property string      $id
 * @property string      $disk
 * @property string      $path
 * @property string      $original_name
 * @property string|null $mime_type
 * @property string|null $extension
 * @property int         $size
 * @property string      $visibility  public|auth|restricted
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $created_by
 */
class File extends Model
{
    use HasUuids;
    use HasEntityPermissions {
        // Rename trait method to avoid collision with File's own isAccessibleBy
        // (which adds the visibility layer on top of the entity-level check).
        isAccessibleBy as checkEntityAccess;
    }

    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'extension',
        'size',
        'visibility',
        'owner_type',
        'owner_id',
        'created_by',
    ];

    // ── Static factories ──────────────────────────────────────────────────────

    public static function fromRequest(\Illuminate\Http\Request $request, string $field, string $disk = ''): static
    {
        $uploaded = $request->file($field);

        if (! $uploaded instanceof UploadedFile) {
            throw new \RuntimeException("Field \"{$field}\" is not a valid uploaded file.");
        }

        return static::fromUploadedFile($uploaded, $disk);
    }

    public static function fromUploadedFile(UploadedFile $file, string $disk = ''): static
    {
        $disk      = $disk ?: config('filesystems.default', 'local');
        $path      = $file->store('files/' . now()->format('Y/m'), $disk);
        $mimeType  = $file->getMimeType();
        $extension = $file->getClientOriginalExtension();

        return static::create([
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $mimeType,
            'extension'     => strtolower($extension),
            'size'          => $file->getSize(),
            'created_by'    => auth()->id(),
        ]);
    }

    public static function fromPath(string $absolutePath, string $disk = '', string $visibility = 'auth'): static
    {
        if (! file_exists($absolutePath)) {
            throw new \RuntimeException("File not found: {$absolutePath}");
        }

        $disk        = $disk ?: config('filesystems.default', 'local');
        $filename    = basename($absolutePath);
        $extension   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType    = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $storagePath = 'files/' . now()->format('Y/m') . '/' . uniqid() . '_' . $filename;

        Storage::disk($disk)->put($storagePath, file_get_contents($absolutePath));

        return static::create([
            'disk'          => $disk,
            'path'          => $storagePath,
            'original_name' => $filename,
            'mime_type'     => $mimeType,
            'extension'     => $extension,
            'size'          => filesize($absolutePath),
            'visibility'    => $visibility,
            'created_by'    => auth()->id(),
        ]);
    }

    public static function fromUrl(string $url, string $disk = '', string $visibility = 'auth'): static
    {
        $response    = Http::get($url);
        $disk        = $disk ?: config('filesystems.default', 'local');
        $filename    = basename(parse_url($url, PHP_URL_PATH)) ?: 'download';
        $extension   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType    = $response->header('Content-Type') ?? 'application/octet-stream';
        $contents    = $response->body();
        $storagePath = 'files/' . now()->format('Y/m') . '/' . uniqid() . '_' . $filename;

        Storage::disk($disk)->put($storagePath, $contents);

        return static::create([
            'disk'          => $disk,
            'path'          => $storagePath,
            'original_name' => $filename,
            'mime_type'     => $mimeType,
            'extension'     => $extension,
            'size'          => strlen($contents),
            'visibility'    => $visibility,
            'created_by'    => auth()->id(),
        ]);
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    /** Route through Innertia's FileController — respects permission check. */
    public function url(): string
    {
        return route('innertia.files.download', $this->id);
    }

    /** Inline view route — respects permission check. */
    public function viewUrl(): string
    {
        return route('innertia.files.view', $this->id);
    }

    /**
     * Direct signed URL from the storage driver.
     * Bypasses Innertia's permission check — use only for temporary access (e.g. emails).
     */
    public function temporaryUrl(int $minutes = 60): string
    {
        return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes));
    }

    // ── Visibility & access control ───────────────────────────────────────────

    /**
     * Check if the given user can access this file.
     *
     * public      → always accessible
     * auth        → any authenticated user
     * restricted  → explicit grant required (entity-level permissions)
     *               also checks: creator, and owner cascade if owner implements canAccess()
     */
    public function isAccessibleBy(Authenticatable $user): bool
    {
        return match ($this->visibility) {
            'public' => true,
            'auth'   => true, // caller already verified authentication upstream
            default  => $this->checkRestricted($user),
        };
    }

    /**
     * Grant file access to specific users.
     * Sets visibility to 'restricted' automatically.
     */
    public function allowUsers(Authenticatable ...$users): static
    {
        $this->grantAccessTo(...$users);

        $this->visibility = 'restricted';
        $this->save();

        return $this;
    }

    /**
     * Grant file access to roles by name.
     * Sets visibility to 'restricted' automatically.
     */
    public function allowRoles(string ...$roles): static
    {
        $this->grantAccessToRoles(...$roles);

        $this->visibility = 'restricted';
        $this->save();

        return $this;
    }

    /**
     * Restrict the file and grant access to a mixed set of users and roles.
     */
    public function restrict(array $users = [], array $roles = []): static
    {
        if (! empty($users)) {
            $this->allowUsers(...$users);
        }

        if (! empty($roles)) {
            $this->allowRoles(...$roles);
        }

        return $this;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function sizeMb(): float
    {
        return round($this->size / 1048576, 2);
    }

    public function sizeKb(): float
    {
        return round($this->size / 1024, 2);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function delete(): ?bool
    {
        $this->revokeAllEntityAccess();

        Storage::disk($this->disk)->delete($this->path);

        return parent::delete();
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function checkRestricted(Authenticatable $user): bool
    {
        // Creator always has access
        if ($this->created_by === (string) $user->getAuthIdentifier()) {
            return true;
        }

        // Entity-level permission check (direct user, role-based, entity cascade)
        if ($this->checkEntityAccess($user)) {
            return true;
        }

        // Cascade via owner — if the owning model implements canAccess()
        if ($this->owner && method_exists($this->owner, 'canAccess')) {
            return $this->owner->canAccess($user);
        }

        return false;
    }
}
