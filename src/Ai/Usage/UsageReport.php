<?php

namespace Innertia\Ai\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Innertia\Ai\Models\UsageEvent;

/**
 * Aggregated AI usage (cost in USD, units, events) by tenant, feature, model and day.
 *
 *   $report = Ai::usageReport('2026-11-01', '2026-11-30', tenantId: '12');
 *   $report->totals(); $report->byFeature(); $report->byTenant(); $report->daily();
 *
 * Range: dates (inclusive) in the report time zone (default America/Santiago); stored timestamps
 * are in the app time zone (normally UTC). Days are folded from hourly SQL buckets in PHP, so the result is DST-correct on any
 * driver. Old rows without `feature` group under their `use`; rows without price count as unpriced.
 */
final class UsageReport
{
    private readonly CarbonImmutable $from;

    private readonly CarbonImmutable $to;

    public function __construct(
        string|\DateTimeInterface $from,
        string|\DateTimeInterface $to,
        private readonly ?string $tenantId = null,
        private readonly ?string $timezone = null,
    ) {
        $tz = $this->tz();
        $this->from = CarbonImmutable::parse($from instanceof \DateTimeInterface ? $from->format('Y-m-d') : $from, $tz)->startOfDay();
        $this->to = CarbonImmutable::parse($to instanceof \DateTimeInterface ? $to->format('Y-m-d') : $to, $tz)->endOfDay();
    }

    /** Report for a calendar month ('YYYY-MM', default current month in the report time zone). */
    public static function month(?string $month = null, ?string $tenantId = null, ?string $timezone = null): self
    {
        $tz = $timezone ?? self::defaultTimezone();
        $start = $month ? CarbonImmutable::createFromFormat('!Y-m', $month, $tz) : CarbonImmutable::now($tz)->startOfMonth();

        return new self($start->format('Y-m-d'), $start->endOfMonth()->format('Y-m-d'), $tenantId, $tz);
    }

    public static function defaultTimezone(): string
    {
        return (string) config('innertia.ai.usage_timezone', config('innertia.billing.timezone', 'America/Santiago'));
    }

    /** @return array{events: int, cost_usd: float, input_tokens: int, output_tokens: int, audio_seconds: float, tts_chars: int, unpriced_events: int} */
    public function totals(): array
    {
        $row = $this->query()->selectRaw($this->sums())->first();

        return $this->shape($row);
    }

    /** @return array<int, array<string, mixed>> grouped by feature (COALESCE(feature, use)), most expensive first */
    public function byFeature(): array
    {
        return $this->grouped('COALESCE(feature, ' . $this->wrap('use') . ')', 'feature');
    }

    /** @return array<int, array<string, mixed>> grouped by model */
    public function byModel(): array
    {
        return $this->grouped('model', 'model');
    }

    /** @return array<int, array<string, mixed>> grouped by tenant_id (null = without tenant) */
    public function byTenant(): array
    {
        return $this->grouped('tenant_id', 'tenant_id');
    }

    /** @return array<int, array{date: string, events: int, cost_usd: float, input_tokens: int, output_tokens: int}> one row per day in range */
    public function daily(): array
    {
        $bucket = $this->hourExpression();
        $rows = $this->query()
            ->selectRaw("{$bucket} as bucket, " . $this->sums())
            ->groupBy(DB::raw($bucket))
            ->get();

        $days = [];
        for ($d = $this->from; $d->lessThanOrEqualTo($this->to); $d = $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d')] + $this->shape(null);
        }

        foreach ($rows as $row) {
            $date = CarbonImmutable::createFromFormat('Y-m-d H', (string) $row->bucket, $this->appTz())->setTimezone($this->tz())->format('Y-m-d');
            if (! isset($days[$date])) {
                continue;
            }
            $shape = $this->shape($row);
            foreach ($shape as $key => $value) {
                $days[$date][$key] += $value;
            }
        }

        foreach ($days as &$day) {
            $day['cost_usd'] = round($day['cost_usd'], 6);
        }

        return array_values($days);
    }

    /** Everything at once (what the consoles render). */
    public function summary(bool $withTenants = false): array
    {
        $summary = [
            'from'       => $this->from->format('Y-m-d'),
            'to'         => $this->to->format('Y-m-d'),
            'timezone'   => $this->tz(),
            'tenant_id'  => $this->tenantId,
            'totals'     => $this->totals(),
            'by_feature' => $this->byFeature(),
            'by_model'   => $this->byModel(),
            'daily'      => $this->daily(),
        ];

        if ($withTenants) {
            $summary['by_tenant'] = $this->byTenant();
        }

        return $summary;
    }

    /** Cost in USD of the range (used by the budget warnings of the products). */
    public function cost(): float
    {
        return $this->totals()['cost_usd'];
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function grouped(string $expression, string $as): array
    {
        return $this->query()
            ->selectRaw("{$expression} as k, " . $this->sums())
            ->groupBy(DB::raw($expression))
            ->get()
            ->map(fn ($row) => [$as => $row->k !== null ? (string) $row->k : null] + $this->shape($row))
            ->sortByDesc(fn ($r) => [$r['cost_usd'], $r['events']])
            ->values()
            ->all();
    }

    private function query(): Builder
    {
        return UsageEvent::query()
            ->where('created_at', '>=', $this->from->setTimezone($this->appTz())->format('Y-m-d H:i:s'))
            ->where('created_at', '<=', $this->to->setTimezone($this->appTz())->format('Y-m-d H:i:s'))
            ->when($this->tenantId !== null, fn (Builder $q) => $q->where('tenant_id', $this->tenantId));
    }

    private function sums(): string
    {
        $audio = $this->jsonNumber('audio_seconds');
        $tts = $this->jsonNumber('tts_chars');

        return 'COUNT(*) as events, COALESCE(SUM(cost_usd), 0) as cost_usd, '
            . 'COALESCE(SUM(prompt_tokens), 0) as input_tokens, COALESCE(SUM(completion_tokens), 0) as output_tokens, '
            . "COALESCE(SUM({$audio}), 0) as audio_seconds, COALESCE(SUM({$tts}), 0) as tts_chars, "
            . 'SUM(CASE WHEN cost_usd IS NULL THEN 1 ELSE 0 END) as unpriced_events';
    }

    private function shape(?object $row): array
    {
        return [
            'events'          => (int) ($row->events ?? 0),
            'cost_usd'        => round((float) ($row->cost_usd ?? 0), 6),
            'input_tokens'    => (int) ($row->input_tokens ?? 0),
            'output_tokens'   => (int) ($row->output_tokens ?? 0),
            'audio_seconds'   => round((float) ($row->audio_seconds ?? 0), 2),
            'tts_chars'       => (int) ($row->tts_chars ?? 0),
            'unpriced_events' => (int) ($row->unpriced_events ?? 0),
        ];
    }

    private function jsonNumber(string $key): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql'  => "CAST(NULLIF(units->>'{$key}', '') AS DOUBLE PRECISION)",
            'mysql', 'mariadb' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(units, '$.{$key}')) AS DECIMAL(20,4))",
            default  => "CAST(json_extract(units, '$.{$key}') AS REAL)",
        };
    }

    private function hourExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql'  => "to_char(created_at, 'YYYY-MM-DD HH24')",
            'mysql', 'mariadb' => "DATE_FORMAT(created_at, '%Y-%m-%d %H')",
            default  => "strftime('%Y-%m-%d %H', created_at)",
        };
    }

    private function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }

    /** Time zone of the stored timestamps (the app's, normally UTC). */
    private function appTz(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    private function tz(): string
    {
        return $this->timezone ?? self::defaultTimezone();
    }
}
