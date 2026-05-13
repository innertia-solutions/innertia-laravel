<?php

namespace Innertia\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class EntityHistory extends Model
{
    protected $table = 'entity_history';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // Solo usamos created_at

    protected $fillable = [
        'id',
        'entity_type',
        'entity_id',
        'action',
        'changes',
        'old_values',
        'new_values',
        'user_id',
        'ip_address',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Relación con el usuario que hizo el cambio
     */
    public function user()
    {
        return $this->belongsTo(\App\Domains\Users\Models\User::class, 'user_id');
    }

    /**
     * Obtener historial de una entidad específica
     */
    public static function forEntity(string $entityType, string $entityId)
    {
        return static::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'desc');
    }

    /**
     * Obtener cambios por usuario
     */
    public static function byUser(string $userId)
    {
        return static::where('user_id', $userId)
            ->orderBy('created_at', 'desc');
    }

    /**
     * Obtener cambios por tipo de acción
     */
    public static function byAction(string $action)
    {
        return static::where('action', $action)
            ->orderBy('created_at', 'desc');
    }
}
