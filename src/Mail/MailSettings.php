<?php

namespace Innertia\Mail;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Innertia\Settings\AppSettingsService;
use Throwable;

/**
 * Platform mail settings (provider, sender and platform brand), stored in the platform
 * Settings (table `settings`, tenant_id NULL) under `mail.*`. No env, no .env edits.
 *
 * Keys are the same ones sumando/pomely already use (mail.mailer, mail.resend_api_key,
 * mail.from_address, mail.from_name, mail.logo_url, mail.brand_color), so adopting the
 * lib keeps the saved configuration.
 *
 * Products opt in with one line in their provider boot():
 *
 *   \Innertia\Mail\MailSettings::boot();   // apply now + before every queued job
 */
final class MailSettings
{
    public const MAILERS = ['smtp', 'resend', 'log'];

    /** Plain-text keys (sender + platform brand). */
    public const PLAIN = ['from_address', 'from_name', 'logo_url', 'brand_color'];

    /** Placeholder the front sends when it does NOT want to change the stored API key. */
    public const KEY_UNCHANGED = '__unchanged__';

    private static bool $booted = false;

    private static ?bool $settingsTable = null;

    /** Applies the stored settings now and before every queued job (long-running workers). */
    public static function boot(): void
    {
        self::apply();

        if (self::$booted) {
            return;
        }

        self::$booted = true;
        Queue::before(fn () => self::apply());
    }

    /**
     * Applies the stored settings over the runtime config. Returns the effective mailer.
     * Never throws (no DB during image build, table not migrated yet, …).
     */
    public static function apply(): string
    {
        try {
            $stored = self::stored();
        } catch (Throwable) {
            return (string) config('mail.default');
        }

        if (filled($stored['mailer'])) {
            config(['mail.default' => $stored['mailer']]);
        }
        if (filled($stored['resend_api_key'])) {
            config(['services.resend.key' => $stored['resend_api_key']]);
        }
        if (filled($stored['from_address'])) {
            config(['mail.from.address' => $stored['from_address']]);
        }
        if (filled($stored['from_name'])) {
            config(['mail.from.name' => $stored['from_name']]);
        }
        if (filled($stored['logo_url'])) {
            config(['innertia.mail.logo_url' => $stored['logo_url']]);
        }
        if (filled($stored['brand_color'])) {
            config(['innertia.mail.brand_color' => $stored['brand_color']]);
        }

        return (string) config('mail.default');
    }

    /**
     * Raw stored values (null when not set). Throws if the DB is unreachable.
     *
     * @return array{mailer: ?string, resend_api_key: ?string, from_address: ?string, from_name: ?string, logo_url: ?string, brand_color: ?string}
     */
    public static function stored(): array
    {
        $empty = array_fill_keys(['mailer', 'resend_api_key', ...self::PLAIN], null);

        if (! self::settingsTableExists()) {
            return $empty;
        }

        $store = self::store();
        $values = $empty;

        foreach (array_keys($empty) as $key) {
            $value = $store->get("mail.{$key}");
            $values[$key] = filled($value) ? (string) $value : null;
        }

        return $values;
    }

    /** Public view of the settings (the API key is never returned in clear). */
    public static function show(): array
    {
        $stored = self::stored();

        return [
            'mailer'             => $stored['mailer'] ?? config('mail.default'),
            'resend_api_key_set' => filled($stored['resend_api_key']),
            'from_address'       => $stored['from_address'] ?? config('mail.from.address'),
            'from_name'          => $stored['from_name'] ?? config('mail.from.name'),
            'logo_url'           => $stored['logo_url'] ?? config('innertia.mail.logo_url'),
            'brand_color'        => $stored['brand_color'] ?? config('innertia.mail.brand_color', Branding::DEFAULT_COLOR),
        ];
    }

    /** Saves already validated values. Empty plain values remove the key. */
    public static function save(array $data): array
    {
        $store = self::store();

        if (! empty($data['mailer'])) {
            $store->set('mail.mailer', $data['mailer']);
        }

        if (array_key_exists('resend_api_key', $data)) {
            $key = trim((string) ($data['resend_api_key'] ?? ''));
            if ($key !== '' && $key !== self::KEY_UNCHANGED) {
                $store->set('mail.resend_api_key', $key, 'string', encrypted: true);
            }
        }

        foreach (self::PLAIN as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null || $value === '') {
                $store->forget("mail.{$key}");
            } else {
                $store->set("mail.{$key}", (string) $value);
            }
        }

        return self::show();
    }

    public static function clearResendKey(): array
    {
        self::store()->forget('mail.resend_api_key');

        return self::show();
    }

    /** Forgets memoized state (tests / fresh application instances). */
    public static function flush(): void
    {
        self::$settingsTable = null;
        self::$booted = false;
    }

    private static function store(): AppSettingsService
    {
        // Platform level (tenant_id NULL) even in saas/open with an active tenant.
        return new class extends AppSettingsService {
            protected function tenantId(): mixed
            {
                return null;
            }
        };
    }

    private static function settingsTableExists(): bool
    {
        if (self::$settingsTable === true) {
            return true;
        }

        // Only the `true` is memoized: the table may appear after a later migrate.
        $exists = Schema::hasTable('settings');
        if ($exists) {
            self::$settingsTable = true;
        }

        return $exists;
    }
}
