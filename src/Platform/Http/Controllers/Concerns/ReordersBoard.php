<?php
namespace Innertia\Platform\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Innertia\Platform\Board\ReorderEntity;

/**
 * Azúcar para exponer `POST {resource}/reorder`. El controller define
 * `boardModelClass(): class-string` y `boardColumnField(): ?string`.
 * NOTA: no aplica autorización — el producto debe protegerlo vía middleware/policy si aplica.
 */
trait ReordersBoard
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    abstract protected function boardModelClass(): string;

    /** Campo de columna del tablero (ej. 'status'). null = sin cambio de columna. */
    protected function boardColumnField(): ?string
    {
        return null;
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'        => ['required', 'string'],
            'column'    => ['nullable', 'string'],
            'before_id' => ['nullable', 'string'],
            'after_id'  => ['nullable', 'string'],
        ]);

        $model = ($this->boardModelClass())::findOrFail($data['id']);

        // Move + reorder atómico: si viene 'column' y hay campo configurado, lo aplica.
        if (($field = $this->boardColumnField()) !== null && array_key_exists('column', $data) && $data['column'] !== null) {
            $model->{$field} = $data['column'];
        }

        $model = (new ReorderEntity(
            model:    $model,
            beforeId: $data['before_id'] ?? null,
            afterId:  $data['after_id'] ?? null,
        ))->execute();

        return response()->json($model);
    }
}
