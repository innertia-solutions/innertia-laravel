<?php

namespace Innertia\Mail;

use Closure;
use Innertia\Facades\Innertia;
use Throwable;

/**
 * Resolved brand of an email: name, logo, color and footer.
 *
 * Resolution order:
 *   - Branding::platform()      → platform mail settings (/platform mail settings) with
 *                                 fallback to config('innertia.mail.*') and app.name.
 *   - Branding::for($tenantId)  → tenant branding (TenantBranding, tenant Settings) with
 *                                 field-by-field fallback to the platform brand. When the
 *                                 tenant has its own brand, `via` carries the platform name
 *                                 ("vía pomely" in the footer).
 *   - Branding::current()       → the brand bound while a mailable renders (InnertiaMailable
 *                                 wraps send/render with Branding::using()), else fromConfig().
 *
 * Branding::fromConfig() reproduces exactly what the mail layout used before branding existed,
 * so products that never touch branding keep the same emails.
 */
final class Branding
{
    public const DEFAULT_COLOR = '#6366f1';

    private static ?Branding $current = null;

    public function __construct(
        public readonly string $name,
        public readonly ?string $logoUrl = null,
        public readonly string $color = self::DEFAULT_COLOR,
        public readonly ?string $footerAddress = null,
        public readonly ?string $footerPhone = null,
        public readonly ?string $via = null,
    ) {}

    /** Legacy behaviour: config('innertia.mail.*') + app.name. */
    public static function fromConfig(): self
    {
        return new self(
            name: (string) config('app.name', 'App'),
            // Raw config values on purpose: identical output to the pre-branding layout.
            logoUrl: filled(config('innertia.mail.logo_url')) ? (string) config('innertia.mail.logo_url') : null,
            color: (string) config('innertia.mail.brand_color', self::DEFAULT_COLOR),
        );
    }

    /** Platform brand: platform mail settings → config fallback. Never throws. */
    public static function platform(): self
    {
        $base = self::fromConfig();

        try {
            $settings = MailSettings::stored();
        } catch (Throwable) {
            return $base;
        }

        return new self(
            name: $base->name,
            logoUrl: self::cleanUrl($settings['logo_url'] ?? null) ?? $base->logoUrl,
            color: self::cleanColor($settings['brand_color'] ?? null) ?? $base->color,
        );
    }

    /**
     * Tenant brand with fallback to the platform brand. `null` tenant (or app mode) = platform.
     */
    public static function for(mixed $tenant = null): self
    {
        $platform = self::platform();
        $tenantId = self::tenantId($tenant);

        if ($tenantId === null) {
            return $platform;
        }

        try {
            $tenantBrand = TenantBranding::get($tenantId);
        } catch (Throwable) {
            return $platform;
        }

        $logo = TenantBranding::logoUrl($tenantBrand);
        $name = filled($tenantBrand['display_name'] ?? null) ? (string) $tenantBrand['display_name'] : null;
        $hasOwnBrand = $name !== null || $logo !== null;

        return new self(
            name: $name ?? $platform->name,
            logoUrl: $logo ?? ($hasOwnBrand ? null : $platform->logoUrl),
            color: self::cleanColor($tenantBrand['color'] ?? null) ?? $platform->color,
            footerAddress: filled($tenantBrand['footer_address'] ?? null) ? (string) $tenantBrand['footer_address'] : null,
            footerPhone: filled($tenantBrand['footer_phone'] ?? null) ? (string) $tenantBrand['footer_phone'] : null,
            via: $hasOwnBrand && $platform->name !== $name ? $platform->name : null,
        );
    }

    /** Brand bound for the email being rendered, or the legacy config brand. */
    public static function current(): self
    {
        return self::$current ?? self::fromConfig();
    }

    /** Runs $callback with $branding bound as current (restores the previous one after). */
    public static function using(?self $branding, Closure $callback): mixed
    {
        if ($branding === null) {
            return $callback();
        }

        $previous = self::$current;
        self::$current = $branding;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public function toArray(): array
    {
        return [
            'name'           => $this->name,
            'logo_url'       => $this->logoUrl,
            'color'          => $this->color,
            'footer_address' => $this->footerAddress,
            'footer_phone'   => $this->footerPhone,
            'via'            => $this->via,
        ];
    }

    private static function tenantId(mixed $tenant): ?string
    {
        if (! Innertia::tenancyEnabled()) {
            return null;
        }

        if ($tenant === null) {
            $tenant = Innertia::tenant();
        }

        if (is_object($tenant)) {
            $tenant = method_exists($tenant, 'getKey') ? $tenant->getKey() : ($tenant->id ?? null);
        }

        return filled($tenant) ? (string) $tenant : null;
    }

    /** Only absolute http(s) URLs: anything else would render as a broken image. */
    public static function cleanUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    public static function cleanColor(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $color = trim($color);

        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) ? $color : null;
    }
}
