<?php

namespace Innertia\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Conversation — un hilo de chat asistido por IA.
 */
class Conversation extends Model
{
    use HasUuids;

    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'title',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at');
    }
}
