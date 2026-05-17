<?php

namespace Innertia\Platform\Http\Controllers;

use Illuminate\Http\Request;
use Innertia\Platform\Models\ActivityLog;
use Innertia\Platform\Models\EntityHistory;

class HistoryController
{
    private const CRUD_ACTIONS = ['created', 'updated', 'deleted', 'restored'];

    public function index(Request $request, string $entityType, string $id)
    {
        $historyEvents  = $this->fetchEntityHistory($entityType, $id);
        $activityEvents = $this->fetchActivityLog($entityType, $id, $historyEvents->isNotEmpty());

        $timeline = $historyEvents
            ->merge($activityEvents)
            ->sortByDesc('created_at')
            ->values();

        return response()->json($timeline);
    }

    // ─── EntityHistory ────────────────────────────────────────────────────────

    private function fetchEntityHistory(string $entityType, string $id): \Illuminate\Support\Collection
    {
        return EntityHistory::where('entity_type', 'like', '%\\' . $entityType)
            ->where('entity_id', $id)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($e) => $this->normalizeHistoryEvent($e));
    }

    private function normalizeHistoryEvent(EntityHistory $event): array
    {
        $changes = [];

        if ($event->action === 'updated' && $event->old_values && $event->new_values) {
            foreach ($event->new_values as $field => $newVal) {
                $changes[] = [
                    'field' => $field,
                    'old'   => $event->old_values[$field] ?? null,
                    'new'   => $newVal,
                ];
            }
        } elseif ($event->action === 'created' && $event->new_values) {
            foreach ($event->new_values as $field => $val) {
                $changes[] = ['field' => $field, 'old' => null, 'new' => $val];
            }
        }

        return [
            'id'          => $event->id,
            'source'      => 'history',
            'type'        => $event->action,
            'description' => $event->reason ?? $this->defaultDescription($event->action),
            'user'        => $event->user ? $this->formatUser($event->user) : null,
            'changes'     => $changes,
            'created_at'  => $event->created_at?->toISOString(),
        ];
    }

    // ─── ActivityLog ──────────────────────────────────────────────────────────

    private function fetchActivityLog(string $entityType, string $id, bool $hasEntityHistory): \Illuminate\Support\Collection
    {
        return ActivityLog::forEntity($entityType, $id)
            ->with('user:id,name,email')
            ->get()
            ->filter(function ($e) use ($hasEntityHistory) {
                // Si EntityHistory ya cubre los eventos CRUD, omitirlos de ActivityLog para evitar duplicados
                if ($hasEntityHistory && in_array($e->action, self::CRUD_ACTIONS)) {
                    return false;
                }
                return true;
            })
            ->map(fn ($e) => $this->normalizeActivityEvent($e));
    }

    private function normalizeActivityEvent(ActivityLog $event): array
    {
        $changes = [];
        $meta    = $event->metadata ?? [];

        if (isset($meta['changes'], $meta['original'])) {
            foreach ($meta['changes'] as $field => $newVal) {
                $changes[] = [
                    'field' => $field,
                    'old'   => $meta['original'][$field] ?? null,
                    'new'   => $newVal,
                ];
            }
        }

        return [
            'id'          => $event->id,
            'source'      => 'activity',
            'type'        => $this->extractType($event->action),
            'description' => $event->description,
            'user'        => $event->user ? $this->formatUser($event->user) : null,
            'changes'     => $changes,
            'created_at'  => $event->created_at?->toISOString(),
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function formatUser($user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    private function extractType(string $action): string
    {
        $parts = explode('.', $action);
        return end($parts);
    }

    private function defaultDescription(string $action): string
    {
        return match ($action) {
            'created'  => 'Registro creado',
            'updated'  => 'Registro actualizado',
            'deleted'  => 'Registro eliminado',
            'restored' => 'Registro restaurado',
            default    => $action,
        };
    }
}
