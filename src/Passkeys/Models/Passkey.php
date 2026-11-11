<?php

namespace Innertia\Passkeys\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Passkey extends Model
{
    use HasUuids;

    protected $table   = 'passkeys';
    protected $guarded = [];
    protected $hidden  = ['credential'];
    protected $casts   = ['last_used_at' => 'datetime'];

    /** Proveedores conocidos por AAGUID (para mostrar en la UI). */
    public const PROVIDERS = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud Keychain',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome en Mac',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
    ];

    public function provider(): ?string
    {
        return self::PROVIDERS[$this->aaguid] ?? null;
    }

    /** Forma pública (sin credencial). */
    public function toPublicArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'provider'     => $this->provider(),
            'created_at'   => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
