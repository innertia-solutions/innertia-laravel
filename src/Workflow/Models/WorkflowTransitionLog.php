<?php

namespace Innertia\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Innertia\Platform\Traits\HasUuid;

class WorkflowTransitionLog extends Model
{
    use HasUuid;

    protected $fillable = [
        'instance_id',
        'from_step',
        'to_step',
        'performed_by',
        'notes',
        'performed_at',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }
}
