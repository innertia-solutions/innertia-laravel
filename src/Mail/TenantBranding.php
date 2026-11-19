<?php

namespace Innertia\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Innertia\Settings\AppSettingsService;
use Throwable;

/**
 * Brand of a tenant (space) for business emails: logo, color, display name and footer.
 *
 * Stored in the tenant Settings (table `settings`, tenant_id = the tenant) under `branding.*`.
 * In app mode (no tenancy) the same keys live at platform level. Branding::for() reads it with
 * fallback to the platform brand.
 *
 * Logo: either a public absolute URL (`logo_url`) or a Files id (`logo_file_id`), served to
 * email clients through a long-lived signed URL (public only for emails). An unreachable logo
 * is dropped (the email shows the name as text instead of a broken image).
 */
final class TenantBranding
{
    public const FIELDS = ['display_name', 'logo_url', 'logo_file_id', 'color', 'footer_address', 'footer_phone'];

    /** Signed logo URLs last a year: emails are opened long after being sent. */
    private const SIGNED_LOGO_MINUTES = 525600;

    /** @return array<string, string|null> */
    public static function get(?string $tenantId): array
    {
        if (! self::settingsTableExists()) {
            return array_fill_keys(self::FIELDS, null);
        }

        $store = self::store($tenantId);
        $values = [];

        foreach (self::FIELDS as $field) {
            $value = $store->get("branding.{$field}");
            $values[$field] = filled($value) ? (string) $value : null;
        }

        return $values;
    }

    /** Saves already validated values. Empty values remove the key. */
    public static function save(?string $tenantId, array $values): array
    {
        $store = self::store($tenantId);

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $value = $values[$field];

            if ($value === null || $value === '') {
                $store->forget("branding.{$field}");
            } else {
                $store->set("branding.{$field}", (string) $value);
            }
        }

        return self::get($tenantId);
    }

    /**
     * Absolute logo URL usable inside an email, or null (name as text).
     *
     * @param array<string, string|null> $branding
     */
    public static function logoUrl(array $branding): ?string
    {
        $url = null;

        if (filled($branding['logo_file_id'] ?? null)) {
            $url = self::fileLogoUrl((string) $branding['logo_file_id']);
        }

        $url ??= Branding::cleanUrl($branding['logo_url'] ?? null);

        if ($url === null) {
            return null;
        }

        return self::reachable($url) ? $url : null;
    }

    private static function fileLogoUrl(string $fileId): ?string
    {
        try {
            $file = \Innertia\Files\Models\File::query()->find($fileId);

            if (! $file) {
                return null;
            }

            return $file->visibility === 'public'
                ? $file->viewUrl()
                : $file->signedViewUrl(self::SIGNED_LOGO_MINUTES);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * HEAD check (cached 1 h) so a dead logo never renders as a broken image.
     * Disabled with config('innertia.mail.verify_logo', true) = false.
     */
    private static function reachable(string $url): bool
    {
        if (! config('innertia.mail.verify_logo', true)) {
            return true;
        }

        try {
            return (bool) Cache::remember('innertia_mail_logo_ok_' . sha1($url), 3600, function () use ($url) {
                $response = Http::timeout(3)->withOptions(['allow_redirects' => true])->head($url);

                if ($response->status() === 405) {
                    $response = Http::timeout(3)->get($url);
                }

                $type = strtolower((string) $response->header('Content-Type'));

                return $response->successful() && ($type === '' || str_starts_with($type, 'image/'));
            });
        } catch (Throwable) {
            return false;
        }
    }

    private static function store(?string $tenantId): AppSettingsService
    {
        return new class($tenantId) extends AppSettingsService {
            public function __construct(private readonly ?string $id) {}

            protected function tenantId(): mixed
            {
                return $this->id;
            }
        };
    }

    private static function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (Throwable) {
            return false;
        }
    }
}
