<?php

namespace Innertia\Billing;

use Illuminate\Support\Str;
use Innertia\Billing\Contracts\MeterPricer;
use Innertia\Billing\Models\UsageRecord;
use Innertia\Billing\Support\BillingClock;
use Innertia\Billing\Support\BillingLog;
use Innertia\Facades\Innertia;
use InvalidArgumentException;

/**
 * Meter catalog and usage recording.
 *
 *   Meters::register(new MeterDefinition('pages.printed', 'Páginas impresas', 'página'));
 *   Meters::record($tenant, 'voice.minutes', 3.5, $endedAt, ['call_id' => $id], sourceRef: "call:{$id}");
 *   Meters::pricer('voice.minutes', new MyVoicePricer);   // custom calculation
 *
 * record() is idempotent by (meter, source_ref): the same source counted twice is stored once
 * (meta.source_id / meta.source_ref are used when no sourceRef is given). It is a no-op while the
 * billing feature is off, so products can always call it.
 */
final class Meters
{
    public const AI_COST = 'ai.cost';
    public const WHATSAPP_MESSAGES = 'whatsapp.messages';
    public const SMS_MESSAGES = 'sms.messages';
    public const VOICE_MINUTES = 'voice.minutes';
    public const EMAILS_SENT = 'emails.sent';
    public const STORAGE_GB = 'storage.gb';
    public const USERS_ACTIVE = 'users.active';

    /** @var array<string, MeterDefinition> */
    private static array $custom = [];

    /** @var array<string, MeterPricer> */
    private static array $pricers = [];

    /** @return array<string, MeterDefinition> */
    public static function standard(): array
    {
        $list = [
            new MeterDefinition(self::AI_COST, 'Inteligencia artificial', 'USD', 'sum', true, 'Costo real del modelo más el margen del plan.'),
            new MeterDefinition(self::WHATSAPP_MESSAGES, 'Mensajes de WhatsApp', 'mensaje'),
            new MeterDefinition(self::SMS_MESSAGES, 'Mensajes SMS', 'mensaje'),
            new MeterDefinition(self::VOICE_MINUTES, 'Minutos de voz salientes', 'minuto'),
            new MeterDefinition(self::EMAILS_SENT, 'Correos enviados', 'correo'),
            new MeterDefinition(self::STORAGE_GB, 'Almacenamiento', 'GB', 'max'),
            new MeterDefinition(self::USERS_ACTIVE, 'Usuarios activos', 'usuario', 'max'),
        ];

        return collect($list)->keyBy('key')->all();
    }

    public static function register(MeterDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            self::$custom[$definition->key] = $definition;
        }
    }

    public static function pricer(string $meter, MeterPricer $pricer): void
    {
        self::$pricers[$meter] = $pricer;
    }

    public static function pricerFor(string $meter): ?MeterPricer
    {
        return self::$pricers[$meter] ?? null;
    }

    /** All known meters (standard + registered). */
    public static function all(): array
    {
        return array_merge(self::standard(), self::$custom);
    }

    /** Meters the product activates (config innertia.billing.meters; null = all). */
    public static function active(): array
    {
        $enabled = config('innertia.billing.meters');
        $all = self::all();

        return is_array($enabled) ? array_intersect_key($all, array_flip($enabled)) : $all;
    }

    public static function get(string $key): ?MeterDefinition
    {
        return self::all()[$key] ?? null;
    }

    public static function flush(): void
    {
        self::$custom = [];
        self::$pricers = [];
    }

    /**
     * Records a consumption of a space. Returns the stored record, or null when billing is off,
     * the meter is not active or the source was already recorded.
     *
     * @param mixed $tenant tenant model, key or id; null = active tenant
     * @param int|null $cost minor units, for cost-valued meters
     */
    public static function record(
        mixed $tenant,
        string $meter,
        float $quantity,
        \DateTimeInterface|string|null $at = null,
        array $meta = [],
        ?string $sourceRef = null,
        ?int $cost = null,
    ): ?UsageRecord {
        if (! BillingFeature::isActive()) {
            return null;
        }

        $definition = self::active()[$meter] ?? null;
        if ($definition === null) {
            if (self::get($meter) === null) {
                throw new InvalidArgumentException("Unknown billing meter \"{$meter}\". Register it with Meters::register().");
            }

            return null; // known but not activated by the product
        }

        $tenantId = self::tenantId($tenant);
        if ($tenantId === null) {
            BillingLog::warning('usage without tenant ignored', ['meter' => $meter]);

            return null;
        }

        $sourceRef ??= isset($meta['source_id']) ? (string) $meta['source_id'] : (isset($meta['source_ref']) ? (string) $meta['source_ref'] : null);
        $occurredAt = $at === null ? now() : ($at instanceof \DateTimeInterface ? \Carbon\Carbon::instance($at) : \Carbon\Carbon::parse($at));

        $id = (string) Str::uuid();
        $inserted = UsageRecord::query()->insertOrIgnore([
            'id'          => $id,
            'tenant_id'   => $tenantId,
            'meter'       => $meter,
            'quantity'    => $quantity,
            'cost'        => $cost,
            'occurred_at' => $occurredAt->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'),
            'source_ref'  => $sourceRef,
            'meta'        => $meta === [] ? null : json_encode($meta),
            'created_at'  => now()->format('Y-m-d H:i:s'),
        ]);

        return $inserted > 0 ? UsageRecord::find($id) : null;
    }

    /** Usage of a space in local dates [start, end] (sum or max by aggregation), excluding nothing. */
    public static function usage(string $tenantId, string $meter, string|\DateTimeInterface $start, string|\DateTimeInterface $end): array
    {
        [$from, $to] = BillingClock::bounds($start, $end);
        $aggregation = (self::get($meter)?->aggregation ?? 'sum') === 'max' ? 'MAX' : 'SUM';

        $row = UsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('meter', $meter)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->selectRaw("COALESCE({$aggregation}(quantity), 0) as quantity, COALESCE(SUM(cost), 0) as cost, COUNT(*) as records")
            ->first();

        return ['quantity' => (float) $row->quantity, 'cost' => (int) $row->cost, 'records' => (int) $row->records];
    }

    public static function tenantId(mixed $tenant): ?string
    {
        if ($tenant === null) {
            $id = Innertia::tenancyEnabled() ? Innertia::tenant()?->getKey() : null;

            return filled($id) ? (string) $id : null;
        }

        if (is_object($tenant)) {
            return method_exists($tenant, 'getKey') ? (string) $tenant->getKey() : (string) ($tenant->id ?? '');
        }

        if (is_int($tenant) || ctype_digit((string) $tenant)) {
            return (string) $tenant;
        }

        $found = Innertia::tenant((string) $tenant);

        return $found ? (string) $found->getKey() : null;
    }
}
