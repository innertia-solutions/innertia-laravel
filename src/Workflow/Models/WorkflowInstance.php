<?php

namespace Innertia\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Innertia\Platform\Traits\HasTenant;
use Innertia\Platform\Traits\HasUuid;
use Innertia\Platform\Traits\Subscribable;
use Innertia\Workflow\Enums\WorkflowStatus;

class WorkflowInstance extends Model
{
    use HasUuid, HasTenant, Subscribable;

    protected $fillable = [
        'tenant_id',
        'definition_id',
        'workflowable_type',
        'workflowable_id',
        'current_step',
        'context',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'status'      => WorkflowStatus::class,
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'definition_id');
    }

    public function workflowable(): MorphTo
    {
        return $this->morphTo();
    }

    public function transitionLogs(): HasMany
    {
        return $this->hasMany(WorkflowTransitionLog::class, 'instance_id')->orderBy('performed_at');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', WorkflowStatus::Active->value);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === WorkflowStatus::Active;
    }

    public function currentStepConfig(): ?array
    {
        return $this->definition->findStep($this->current_step);
    }
}
