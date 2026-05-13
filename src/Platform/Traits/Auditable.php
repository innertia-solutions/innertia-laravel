<?php

namespace Innertia\Platform\Traits;

use Innertia\Facades\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait para capturar automáticamente cambios en modelos
 * Se debe usar en modelos que requieran auditoría automática
 */
trait Auditable
{
    /**
     * Acciones que deben ser excluidas del audit logging
     * Los modelos pueden sobrescribir este arreglo
     */
    protected array $auditExcludeActions = [];

    /**
     * Determina si el audit logging está habilitado para este modelo
     * Los modelos pueden sobrescribir este método
     */
    protected function isAuditEnabled(): bool
    {
        return true;
    }

    /**
     * Obtiene los campos que deben ser excluidos del audit
     * Los modelos definen $auditExcludes como propiedad, similar a $fillable
     */
    protected function getAuditExcludes(): array
    {
        return $this->auditExcludes ?? [];
    }

    /**
     * Obtiene las acciones que deben ser excluidas del audit
     */
    protected function getAuditExcludeActions(): array
    {
        return $this->auditExcludeActions ?? [];
    }

    /**
     * Determina si una acción debe ser auditada
     */
    protected function shouldAuditAction(string $action): bool
    {
        return !in_array($action, $this->getAuditExcludeActions());
    }

    /**
     * Filtra los cambios excluyendo campos no relevantes para auditoría
     */
    protected function filterAuditableChanges(array $changes): array
    {
        $excludes = $this->getAuditExcludes();
        return array_diff_key($changes, array_flip($excludes));
    }

    protected static function bootAuditable(): void
    {
        // Registro cuando se crea un modelo
        static::created(function (Model $model) {
            if (!$model->isAuditEnabled() || !$model->shouldAuditAction('created')) {
                return;
            }

            ActivityLogger::log(
                action: 'created',
                entityType: class_basename($model),
                entityId: $model->getKey(),
                description: class_basename($model) . ' created',
                metadata: [
                    'attributes' => $model->getAttributes(),
                ]
            );
        });

        // Registro cuando se actualiza un modelo
        static::updated(function (Model $model) {
            if (!$model->isAuditEnabled() || !$model->shouldAuditAction('updated')) {
                return;
            }

            $changes = $model->getChanges();
            $filteredChanges = $model->filterAuditableChanges($changes);

            // Si no hay cambios relevantes después del filtrado, no registrar
            if (empty($filteredChanges)) {
                return;
            }

            $original = $model->getOriginal();

            ActivityLogger::log(
                action: 'updated',
                entityType: class_basename($model),
                entityId: $model->getKey(),
                description: class_basename($model) . ' updated',
                metadata: [
                    'changes' => $filteredChanges,
                    'original' => array_intersect_key($original, $filteredChanges),
                ]
            );
        });

        // Registro cuando se elimina un modelo
        static::deleted(function (Model $model) {
            if (!$model->isAuditEnabled() || !$model->shouldAuditAction('deleted')) {
                return;
            }

            ActivityLogger::log(
                action: 'deleted',
                entityType: class_basename($model),
                entityId: $model->getKey(),
                description: class_basename($model) . ' deleted',
                metadata: [
                    'attributes' => $model->getAttributes(),
                ]
            );
        });
    }
}
