<?php

namespace Innertia\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Innertia\Platform\Traits\HasUuid;

class WorkflowDefinition extends Model
{
    use HasUuid;

    protected $fillable = [
        'tenant_id',
        'entity_type',
        'name',
        'label',
        'config',
        'source_yaml',
        'is_template',
    ];

    protected $casts = [
        'config'      => 'array',
        'is_template' => 'boolean',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'definition_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Retorna definiciones del tenant + plantillas globales (tenant_id = null).
     * NO usa HasTenant porque los templates globales tienen tenant_id = NULL.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
        });
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function findStep(string $key): ?array
    {
        return collect($this->config['steps'])->firstWhere('key', $key);
    }

    public function findTransition(string $fromStep, string $toStep): ?array
    {
        return collect($this->config['transitions'])->first(
            fn($t) => $t['from'] === $fromStep && $t['to'] === $toStep
        );
    }

    public function transitionsFrom(string $stepKey): array
    {
        return collect($this->config['transitions'])
            ->filter(fn($t) => $t['from'] === $stepKey)
            ->values()
            ->toArray();
    }
}
