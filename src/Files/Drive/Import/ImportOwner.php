<?php

namespace Innertia\Files\Drive\Import;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dueño (owner_type/owner_id) con que un import escribe. File no tiene tenant: los productos
 * aíslan archivos por dueño, así que todo dedupe y lock del import se acota a él.
 */
final class ImportOwner
{
    private function __construct(
        public readonly ?string $type,
        public readonly ?string $id,
    ) {}

    /** @param array{owner_type?: ?string, owner_id?: ?string} $attributes */
    public static function from(array $attributes): self
    {
        return new self($attributes['owner_type'] ?? null, isset($attributes['owner_id']) ? (string) $attributes['owner_id'] : null);
    }

    /** @return array{owner_type: ?string, owner_id: ?string} */
    public function attributes(): array
    {
        return ['owner_type' => $this->type, 'owner_id' => $this->id];
    }

    /** Filtra por dueño comparando null con IS NULL. */
    public function scope(Builder $query): Builder
    {
        foreach ($this->attributes() as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /** Fragmento estable para claves de lock. */
    public function key(): string
    {
        return substr(md5(($this->type ?? '') . '|' . ($this->id ?? '')), 0, 16);
    }

    /** Modelo mínimo para pasar como `owner` a CreateDirectory (sin cargar la entidad real). */
    public function model(): ?Model
    {
        if ($this->type === null) {
            return null;
        }

        return new class($this->type, $this->id) extends Model {
            // Defaults: Eloquent instancia el modelo sin argumentos al hacer boot.
            public function __construct(private string $ownerType = '', private ?string $ownerId = null)
            {
                parent::__construct([]);
            }

            public function getMorphClass(): string
            {
                return $this->ownerType;
            }

            public function getKey(): mixed
            {
                return $this->ownerId;
            }
        };
    }
}
