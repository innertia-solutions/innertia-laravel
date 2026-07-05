<?php

namespace Innertia\Platform\Board;

use Illuminate\Database\Eloquent\Model;
use Innertia\Platform\Contracts\UseCase;

/**
 * Recalcula `position` de $model dentro de su columna, colocándolo entre
 * beforeId (arriba) y afterId (abajo). El caller ya dejó $model en su columna
 * destino (ej. seteó `status`) antes de invocar.
 */
class ReorderEntity extends UseCase
{
    public function __construct(
        public readonly Model $model,
        public readonly ?string $beforeId = null,
        public readonly ?string $afterId = null,
    ) {}

    public function execute(): Model
    {
        $step = (float) $this->model::BOARD_POSITION_STEP;
        [$before, $after] = $this->neighborPositions();
        $pos = $this->computePosition($before, $after, $step);

        // Si el midpoint colisionó con un vecino (precisión float), rebalancea y recomputa.
        if ($before !== null && $after !== null && ($pos <= $before || $pos >= $after)) {
            $this->rebalance();
            [$before, $after] = $this->neighborPositions();
            $pos = $this->computePosition($before, $after, $step);
        }

        $this->model->position = $pos;
        $this->model->save();

        return $this->model;
    }

    private function computePosition(?float $before, ?float $after, float $step): float
    {
        if ($before !== null && $after !== null) {
            return ($before + $after) / 2;
        }
        if ($before !== null) {
            return $before + $step;
        }
        if ($after !== null) {
            return $after - $step;
        }
        return $step;
    }

    /** @return array{0: float|null, 1: float|null} */
    private function neighborPositions(): array
    {
        return [$this->resolvePosition($this->beforeId), $this->resolvePosition($this->afterId)];
    }

    private function resolvePosition(?string $id): ?float
    {
        if (! $id) {
            return null;
        }
        $val = $this->model->newBoardQuery()->whereKey($id)->value('position');

        return $val === null ? null : (float) $val;
    }

    /** Renumera toda la columna con posiciones espaciadas por STEP. */
    private function rebalance(): void
    {
        $step = (float) $this->model::BOARD_POSITION_STEP;
        $rows = $this->model->newBoardQuery()->orderByBoard()->get();
        $i = 1;
        foreach ($rows as $row) {
            if ($row->getKey() === $this->model->getKey()) {
                continue;
            }
            $row->position = $i * $step;
            $row->saveQuietly();
            $i++;
        }
    }
}
