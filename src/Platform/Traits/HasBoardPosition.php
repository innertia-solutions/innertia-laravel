<?php

namespace Innertia\Platform\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Orden manual del tablero (kanban) vía columna `position` (double). Opt-in.
 * Personalización:
 *   - boardColumnKey(): ?string  → columna que scopea la secuencia (ej. 'status').
 *                                   null = secuencia única en toda la tabla.
 */
trait HasBoardPosition
{
    public const BOARD_POSITION_STEP = 1000;

    protected static function bootHasBoardPosition(): void
    {
        static::creating(function (Model $model) {
            if ($model->position === null) {
                $model->position = $model->nextBoardPosition();
            }
        });
    }

    public function initializeHasBoardPosition(): void
    {
        $this->mergeCasts(['position' => 'float']);
    }

    protected function boardColumnKey(): ?string
    {
        return null;
    }

    public function boardColumn(): ?string
    {
        return $this->boardColumnKey();
    }

    public function newBoardQuery(): Builder
    {
        $q = static::query();
        if (($key = $this->boardColumnKey()) !== null) {
            $q->where($key, $this->{$key});
        }
        return $q;
    }

    public function nextBoardPosition(): float
    {
        $max = (float) ($this->newBoardQuery()->max('position') ?? 0);
        return $max + self::BOARD_POSITION_STEP;
    }

    public function scopeOrderByBoard(Builder $query): Builder
    {
        return $query->orderBy('position')->orderByDesc('created_at');
    }
}
