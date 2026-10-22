<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Message — un mensaje dentro de una Conversation.
 *
 * Solo tiene `created_at` → $timestamps = false.
 */
class Message extends Model
{
    use HasUuids;

    protected $table = 'ai_messages';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'attachments',
        'created_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'created_at'  => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
