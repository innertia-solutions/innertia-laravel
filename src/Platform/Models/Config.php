<?php

namespace Innertia\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Config — registro polimórfico de configuración.
 *
 * Puede pertenecer a cualquier modelo (User, Tenant, Integration…) y
 * representa preferencias, settings, módulos o configs de integraciones.
 */
class Config extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'type',
        'key',
        'value',
        'cast',
        'privacy',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** Deserializa value según el cast declarado. */
    public function getValue(): mixed
    {
        return match ($this->cast) {
            'boolean'   => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer'   => (int) $this->value,
            'json'      => json_decode($this->value, true),
            'encrypted' => Crypt::decryptString($this->value),
            default     => $this->value,
        };
    }

    /** Serializa un valor para almacenarlo según el cast. */
    public static function encodeValue(mixed $value, string $cast): string
    {
        return match ($cast) {
            'json'      => json_encode($value),
            'encrypted' => Crypt::encryptString((string) $value),
            'boolean'   => $value ? 'true' : 'false',
            default     => (string) $value,
        };
    }
}
