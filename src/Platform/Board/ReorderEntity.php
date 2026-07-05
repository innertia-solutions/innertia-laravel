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
    private const EPSILON = 0.0000001;

    public function __construct(
        public readonly Model $model,
        public readonly ?string $beforeId = null,
        public readonly ?string $afterId = null,
    ) {}

    public function execute(): Model
    {
        $step = (float) $this->model::BOARD_POSITION_STEP;
        [$before, $after] = $this->neighborPositions();

        if ($before !== null && $after !== null) {
            if (abs($before - $after) < self::EPSILON) {
                $this->rebalance();
                [$before, $after] = $this->neighborPositions();
            }
            $pos = ($before + $after) / 2;
        } elseif ($before !== null) {
            $pos = $before + $step;
        } elseif ($after !== null) {
            $pos = $after - $step;
        } else {
            $pos = $step;
        }

        $this->model->position = $pos;
        $this->model->save();

        return $this->model;
    }

    /** @return array{0: float|null, 1: float|null} */
    private function neighborPositions(): array
    {
        $before = $this->beforeId
            ? (float) $this->model->newBoardQuery()->whereKey($this->beforeId)->value('position')
            : null;
        $after = $this->afterId
            ? (float) $this->model->newBoardQuery()->whereKey($this->afterId)->value('position')
            : null;

        return [$before, $after];
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
