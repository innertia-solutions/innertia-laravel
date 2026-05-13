<?php

namespace Innertia\Platform\Services;

use Innertia\Platform\Models\EntityHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EntityHistoryService
{
    /**
     * Registrar creación de entidad
     */
    public function recordCreated(Model $model, ?string $reason = null): EntityHistory
    {
        return $this->record(
            model: $model,
            action: 'created',
            changes: null,
            oldValues: null,
            newValues: $model->getAttributes(),
            reason: $reason
        );
    }

    /**
     * Registrar actualización de entidad
     */
    public function recordUpdated(Model $model, ?string $reason = null): EntityHistory
    {
        $changes = $model->getChanges();
        $original = $model->getOriginal();

        // Solo los campos que realmente cambiaron
        $oldValues = array_intersect_key($original, $changes);

        return $this->record(
            model: $model,
            action: 'updated',
            changes: array_keys($changes),
            oldValues: $oldValues,
            newValues: $changes,
            reason: $reason
        );
    }

    /**
     * Registrar eliminación de entidad
     */
    public function recordDeleted(Model $model, ?string $reason = null): EntityHistory
    {
        return $this->record(
            model: $model,
            action: 'deleted',
            changes: null,
            oldValues: $model->getOriginal(),
            newValues: null,
            reason: $reason
        );
    }

    /**
     * Registrar restauración de entidad (soft delete)
     */
    public function recordRestored(Model $model, ?string $reason = null): EntityHistory
    {
        return $this->record(
            model: $model,
            action: 'restored',
            changes: ['deleted_at'],
            oldValues: ['deleted_at' => $model->getOriginal('deleted_at')],
            newValues: ['deleted_at' => null],
            reason: $reason
        );
    }

    /**
     * Registrar cambio personalizado
     */
    public function recordCustom(
        Model $model,
        string $action,
        ?array $changes = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null
    ): EntityHistory {
        return $this->record(
            model: $model,
            action: $action,
            changes: $changes,
            oldValues: $oldValues,
            newValues: $newValues,
            reason: $reason
        );
    }

    /**
     * Método base para registrar historial
     */
    protected function record(
        Model $model,
        string $action,
        ?array $changes = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null
    ): EntityHistory {
        return EntityHistory::create([
            'entity_type' => get_class($model),
            'entity_id'   => (string) $model->getKey(),
            'action'      => $action,
            'changes'     => $changes,
            'old_values'  => $oldValues,
            'new_values'  => $newValues,
            'user_id'     => $this->getAuthenticatedUserId(),
            'ip_address'  => request()->ip(),
            'reason'      => $reason,
            'created_at'  => now(),
        ]);
    }

    /**
     * Obtener historial completo de una entidad
     */
    public function getEntityHistory(string $entityType, string $entityId): array
    {
        return EntityHistory::forEntity($entityType, $entityId)
            ->with('user:id,name,email')
            ->get()
            ->toArray();
    }

    /**
     * Obtener estado de una entidad en un punto específico del tiempo
     */
    public function getEntityStateAt(string $entityType, string $entityId, $timestamp): ?array
    {
        $history = EntityHistory::forEntity($entityType, $entityId)
            ->where('created_at', '<=', $timestamp)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($history->isEmpty()) {
            return null;
        }

        $state = [];

        // Reconstruir el estado aplicando cambios en orden cronológico inverso
        foreach ($history->reverse() as $record) {
            if ($record->action === 'created') {
                $state = $record->new_values ?? [];
            } elseif ($record->action === 'updated') {
                $state = array_merge($state, $record->new_values ?? []);
            } elseif ($record->action === 'deleted') {
                $state['deleted_at'] = $record->created_at;
            } elseif ($record->action === 'restored') {
                unset($state['deleted_at']);
            }
        }

        return $state;
    }

    /**
     * Comparar dos versiones de una entidad
     */
    public function compareVersions(string $entityType, string $entityId, $timestamp1, $timestamp2): array
    {
        $state1 = $this->getEntityStateAt($entityType, $entityId, $timestamp1);
        $state2 = $this->getEntityStateAt($entityType, $entityId, $timestamp2);

        if (!$state1 || !$state2) {
            return ['error' => 'Cannot compare - entity state not found'];
        }

        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($state1), array_keys($state2)));

        foreach ($allKeys as $key) {
            $value1 = $state1[$key] ?? null;
            $value2 = $state2[$key] ?? null;

            if ($value1 !== $value2) {
                $changes[$key] = [
                    'from' => $value1,
                    'to' => $value2,
                ];
            }
        }

        return $changes;
    }

    private function getAuthenticatedUserId(): ?string
    {
        try {
            return Auth::check() ? (string) Auth::id() : null;
        } catch (\Exception $e) {
            // Silenciosamente continuar si Auth no está disponible o configurado
            return null;
        }
    }
}
