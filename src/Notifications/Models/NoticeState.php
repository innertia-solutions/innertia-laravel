<?php

namespace Innertia\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Open notice per (tenant, type, subject_key, user): powers debounce, grouping and resolve().
 * resolved_at != null → closed (a new send opens a fresh one).
 */
class NoticeState extends Model
{
    use HasUuids;

    protected $table = 'notice_states';

    protected $fillable = ['tenant_id', 'type', 'subject_key', 'user_id', 'notification_id', 'count', 'last_sent_at', 'resolved_at'];

    protected $casts = [
        'count'        => 'integer',
        'last_sent_at' => 'datetime',
        'resolved_at'  => 'datetime',
    ];
}
