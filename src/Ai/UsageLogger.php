<?php

namespace Innertia\Ai;

use Illuminate\Support\Facades\Schema;
use Innertia\Ai\Events\AiUsageRecorded;
use Innertia\Ai\Models\UsageEvent;
use Innertia\Ai\Usage\UsagePricing;
use Innertia\Facades\Innertia;

/**
 * Registra consumo de IA en ai_usage_events.
 *
 *   Ai::recordUsage('call_summary', ['input_tokens' => 1200, 'output_tokens' => 300], 'gpt-4.1-mini');
 *   Ai::recordUsage('transcription', ['audio_seconds' => 95], 'whisper-1');
 *   Ai::recordUsage('tts', ['tts_chars' => 480], 'speech-02');
 *
 * Toma tenant y organización activos, calcula cost_usd con el precio vigente del modelo y dispara
 * AiUsageRecorded (Billing lo mide como `ai.cost`). record() (API previa por tokens) sigue igual y
 * ahora también guarda tenant y costo cuando las columnas existen.
 */
class UsageLogger
{
    private const COLUMNS_KEY = 'innertia.ai.usage_columns';

    /**
     * @param array<string, int|float> $units input_tokens, output_tokens, audio_seconds, tts_chars (+ extra keys kept as-is)
     * @param array{use?: string, provider_id?: string, user_id?: string, tenant_id?: string, organization_id?: string} $context
     */
    public function recordUsage(string $feature, array $units, string|Models\AiModel|null $model = null, array $context = []): UsageEvent
    {
        $resolved = UsagePricing::model($model, $context['provider_id'] ?? null);
        $input = (int) ($units['input_tokens'] ?? 0);
        $output = (int) ($units['output_tokens'] ?? 0);

        return $this->store([
            'provider_id'       => $context['provider_id'] ?? $resolved?->provider_id,
            'model'             => $model instanceof Models\AiModel ? $model->key : $model,
            'use'               => $context['use'] ?? $feature,
            'feature'           => $feature,
            'prompt_tokens'     => $input,
            'completion_tokens' => $output,
            'total_tokens'      => $input + $output,
            'user_id'           => $context['user_id'] ?? $this->authUserId(),
            'units'             => $units,
            'cost_usd'          => UsagePricing::cost($resolved, $units),
        ], $context);
    }

    public function record(
        string $use,
        ?string $providerId = null,
        ?string $model = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
        ?string $userId = null,
    ): UsageEvent {
        $units = ['input_tokens' => $promptTokens, 'output_tokens' => $completionTokens];

        return $this->store([
            'provider_id'       => $providerId,
            'model'             => $model,
            'use'               => $use,
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $promptTokens + $completionTokens,
            'user_id'           => $userId,
            'cost_usd'          => UsagePricing::cost(UsagePricing::model($model, $providerId), $units),
        ]);
    }

    /** Total de tokens registrados para un uso (o todos si null). */
    public function totalTokens(?string $use = null): int
    {
        return (int) UsageEvent::query()
            ->when($use !== null, fn ($q) => $q->where('use', $use))
            ->sum('total_tokens');
    }

    private function store(array $attributes, array $context = []): UsageEvent
    {
        $attributes += [
            'tenant_id'       => $context['tenant_id'] ?? $this->tenantId(),
            'organization_id' => $context['organization_id'] ?? $this->organizationId(),
            'created_at'      => now(),
        ];

        // Products that did not migrate yet keep working: only known columns are written.
        $columns = $this->columns();
        if ($columns !== null) {
            $attributes = array_intersect_key($attributes, array_flip($columns));
        }

        $event = UsageEvent::create($attributes);

        event(new AiUsageRecorded($event));

        return $event;
    }

    /** @return string[]|null column list of ai_usage_events (memoized per application instance) */
    private function columns(): ?array
    {
        $app = app();
        if ($app->bound(self::COLUMNS_KEY)) {
            return $app->make(self::COLUMNS_KEY);
        }

        try {
            $columns = Schema::getColumnListing('ai_usage_events');
        } catch (\Throwable) {
            $columns = [];
        }

        $columns = $columns === [] ? null : $columns;
        // Only memoize a complete schema: a table still missing the new columns is re-read after `migrate`.
        if ($columns !== null && in_array('cost_usd', $columns, true)) {
            $app->instance(self::COLUMNS_KEY, $columns);
        }

        return $columns;
    }

    private function tenantId(): ?string
    {
        try {
            $id = Innertia::tenancyEnabled() ? Innertia::tenant()?->getKey() : null;

            return filled($id) ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function organizationId(): ?string
    {
        try {
            $id = Innertia::organization()?->current();

            return filled($id) ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function authUserId(): ?string
    {
        try {
            $id = auth()->id();

            return filled($id) ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
