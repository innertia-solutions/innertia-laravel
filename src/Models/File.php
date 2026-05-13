<?php

namespace Innertia\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
 *   $file->allowUsers($user1, $user2)
 *   $file->allowRoles('admin', 'manager')
 *   $file->restrict(users: [$user1], roles: ['admin'])
 *   $file->isAccessibleBy($user)
 */
class File extends Model
{
    use HasUuids;

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

        $disk      = $disk ?: config('filesystems.default', 'local');
        $filename  = basename($absolutePath);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType  = mime_content_type($absolutePath) ?: 'application/octet-stream';
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
        $response  = Http::get($url);
        $disk      = $disk ?: config('filesystems.default', 'local');
        $filename  = basename(parse_url($url, PHP_URL_PATH)) ?: 'download';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType  = $response->header('Content-Type') ?? 'application/octet-stream';
        $contents  = $response->body();
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

    // ── Permissions ───────────────────────────────────────────────────────────

    public function isAccessibleBy(Authenticatable $user): bool
    {
        return match ($this->visibility) {
            'public' => true,
            'auth'   => true, // caller already verified authentication
            default  => $this->checkRestricted($user),
        };
    }

    private function checkRestricted(Authenticatable $user): bool
    {
        $userId = (string) $user->getAuthIdentifier();

        // Direct user permission
        $direct = $this->permissions()
            ->where('permissionable_type', get_class($user))
            ->where('permissionable_id', $userId)
            ->exists();

        if ($direct) {
            return true;
        }

        // Role-based permission (Spatie roles)
        if (method_exists($user, 'getRoleNames')) {
            $roles = $user->getRoleNames()->toArray();
            $hasRole = $this->permissions()
                ->where('permissionable_type', 'role')
                ->whereIn('permissionable_id', $roles)
                ->exists();

            if ($hasRole) {
                return true;
            }
        }

        // Cascade via owner — if the owner model implements canAccess()
        if ($this->owner && method_exists($this->owner, 'canAccess')) {
            return $this->owner->canAccess($user);
        }

        // Creator always has access
        return $this->created_by === $userId;
    }

    public function allowUsers(Authenticatable ...$users): static
    {
        foreach ($users as $user) {
            $this->permissions()->firstOrCreate([
                'permissionable_type' => get_class($user),
                'permissionable_id'   => (string) $user->getAuthIdentifier(),
            ]);
        }

        $this->visibility = 'restricted';
        $this->save();

        return $this;
    }

    public function allowRoles(string ...$roles): static
    {
        foreach ($roles as $role) {
            $this->permissions()->firstOrCreate([
                'permissionable_type' => 'role',
                'permissionable_id'   => $role,
            ]);
        }

        $this->visibility = 'restricted';
        $this->save();

        return $this;
    }

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
        Storage::disk($this->disk)->delete($this->path);

        return parent::delete();
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(FilePermission::class, 'file_id');
    }
}
