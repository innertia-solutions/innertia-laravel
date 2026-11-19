<?php

namespace Innertia\PublicApi\Http;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Cursor pagination of the public API: `?cursor=&limit=` (≤ 100, default 25) and `?updated_since=` (ISO 8601).
 * Response: `{data: [...], meta: {next_cursor, limit}}`.
 *
 *   return CursorPage::respond($request, Call::query()->where(...), fn (Call $c) => CallResource::v1($c));
 *
 * Order: `$orderColumn` desc + primary key (stable). `updated_since` filters on `updated_at` (configurable).
 */
final class CursorPage
{
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 25;

    public static function respond(Request $request, BuilderContract $query, ?callable $transform = null, string $orderColumn = 'id', string $updatedColumn = 'updated_at'): JsonResponse
    {
        return new JsonResponse(self::paginate($request, $query, $transform, $orderColumn, $updatedColumn));
    }

    /** @return array{data: array, meta: array{next_cursor: ?string, limit: int}} */
    public static function paginate(Request $request, BuilderContract $query, ?callable $transform = null, string $orderColumn = 'id', string $updatedColumn = 'updated_at'): array
    {
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw ValidationException::withMessages(['limit' => 'El límite debe estar entre 1 y ' . self::MAX_LIMIT . '.']);
        }

        if ($since = $request->query('updated_since')) {
            try {
                $query->where($updatedColumn, '>=', Carbon::parse((string) $since));
            } catch (\Throwable) {
                throw ValidationException::withMessages(['updated_since' => 'Fecha inválida (usa ISO 8601).']);
            }
        }

        $model = $query->getModel();
        $query->orderByDesc($orderColumn);
        if ($orderColumn !== $model->getKeyName()) {
            $query->orderByDesc($model->getKeyName());
        }

        try {
            $page = $query->cursorPaginate($limit, ['*'], 'cursor', $request->query('cursor'));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'Cursor inválido.']);
        }

        $items = collect($page->items())->map(fn ($item) => $transform ? $transform($item) : $item)->values()->all();

        return ['data' => $items, 'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'limit' => $limit]];
    }
}
