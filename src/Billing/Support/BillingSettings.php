<?php

namespace Innertia\Billing\Support;

use Innertia\Facades\Settings;

/**
 * Platform-level billing settings (Settings table, tenant null), editable from platform:
 *   usd_clp_rate           USD→CLP used to value AI cost
 *   transfer_instructions  bank data shown in the invoice email/PDF
 *   issuer_name / issuer_tax_id / issuer_address   issuer block of the PDF (default: platform brand)
 *   reminders_enabled / reminder_days_before ("7,3") / reminder_on_due / reminder_overdue_every_days /
 *   reminder_overdue_max / reminder_escalate_after / reminder_escalation_email   payment reminders
 * Fallback: config('innertia.billing.*') (reminders: innertia.billing.reminders.*).
 */
final class BillingSettings
{
    public const KEYS = [
        'usd_clp_rate', 'transfer_instructions', 'issuer_name', 'issuer_tax_id', 'issuer_address',
        // Payment reminders (stage 3)
        'reminders_enabled', 'reminder_days_before', 'reminder_on_due', 'reminder_overdue_every_days',
        'reminder_overdue_max', 'reminder_escalate_after', 'reminder_escalation_email',
    ];

    public static function all(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = self::get($key);
        }
        $out['usd_clp_rate'] = (float) ($out['usd_clp_rate'] ?: config('innertia.billing.usd_clp_rate', 950));
        $out['reminders_enabled'] = self::bool($out['reminders_enabled']);
        $out['reminder_on_due'] = self::bool($out['reminder_on_due']);
        $out['reminder_days_before'] = implode(',', self::daysBefore());
        foreach (['reminder_overdue_every_days', 'reminder_overdue_max', 'reminder_escalate_after'] as $key) {
            $out[$key] = (int) $out[$key];
        }

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
            'reminders_enabled'           => config('innertia.billing.reminders.enabled', true),
            'reminder_days_before'        => config('innertia.billing.reminders.days_before', '3'),
            'reminder_on_due'             => config('innertia.billing.reminders.on_due', true),
            'reminder_overdue_every_days' => config('innertia.billing.reminders.overdue_every_days', 7),
            'reminder_overdue_max'        => config('innertia.billing.reminders.overdue_max', 6),
            'reminder_escalate_after'     => config('innertia.billing.reminders.escalate_after', 2),
            'reminder_escalation_email'   => config('innertia.billing.reminders.escalation_email'),
            default                 => null,
        };
    }

    public static function save(array $values): array
    {
        foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
            if ($value === null || $value === '') {
                self::store()->forget("billing.{$key}");
            } else {
                self::store()->set("billing.{$key}", is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        }

        return self::all();
    }

    /** Days before the due date with a reminder, descending ("7,3" → [7, 3]). */
    public static function daysBefore(): array
    {
        $raw = self::get('reminder_days_before');
        $days = array_map('intval', is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY));
        $days = array_values(array_unique(array_filter($days, fn ($d) => $d > 0 && $d <= 60)));
        rsort($days);

        return $days;
    }

    public static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
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
