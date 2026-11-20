<?php

namespace Innertia\Billing\Support;

use Innertia\Facades\Settings;

/**
 * Platform-level billing settings (Settings table, tenant null), editable from platform:
 *   usd_clp_rate           USD→CLP used to value AI cost
 *   transfer_instructions  bank data shown in the invoice email/PDF
 *   issuer_name / issuer_tax_id / issuer_address   issuer block of the PDF (default: platform brand)
 * Fallback: config('innertia.billing.*').
 */
final class BillingSettings
{
    public const KEYS = ['usd_clp_rate', 'transfer_instructions', 'issuer_name', 'issuer_tax_id', 'issuer_address'];

    public static function all(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = self::get($key);
        }
        $out['usd_clp_rate'] = (float) ($out['usd_clp_rate'] ?: config('innertia.billing.usd_clp_rate', 950));

        return $out;
    }

    public static function get(string $key): mixed
    {
        try {
            $value = self::store()->get("billing.{$key}");
        } catch (\Throwable) {
            $value = null;
        }

        return $value ?? match ($key) {
            'usd_clp_rate'          => config('innertia.billing.usd_clp_rate', 950),
            'transfer_instructions' => config('innertia.billing.transfer_instructions'),
            'issuer_name'           => config('innertia.billing.issuer.name'),
            'issuer_tax_id'         => config('innertia.billing.issuer.tax_id'),
            'issuer_address'        => config('innertia.billing.issuer.address'),
            default                 => null,
        };
    }

    public static function save(array $values): array
    {
        foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
            if ($value === null || $value === '') {
                self::store()->forget("billing.{$key}");
            } else {
                self::store()->set("billing.{$key}", (string) $value);
            }
        }

        return self::all();
    }

    public static function issuer(): array
    {
        return array_filter([
            'name'    => self::get('issuer_name'),
            'tax_id'  => self::get('issuer_tax_id'),
            'address' => self::get('issuer_address'),
        ]);
    }

    private static function store(): mixed
    {
        $service = Settings::getFacadeRoot();

        return method_exists($service, 'platform') ? $service->platform() : $service;
    }
}
