<?php

namespace Innertia\Services;

use Innertia\Models\ActivityLog;

class ActivityLogService
{
    public function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $userId = null,
        ?string $traceId = null,
        ?array $metadata = [],
        ?string $description = null
    ): ActivityLog {
        // Si no se proporciona user_id, intentar obtenerlo del usuario autenticado
        if (!$userId) {
            try {
                if (\Illuminate\Support\Facades\Auth::check()) {
                    $userId = \Illuminate\Support\Facades\Auth::id();
                }
            } catch (\Exception $e) {
                // Silenciosamente continuar si Auth no está disponible o configurado
                $userId = null;
            }
        }

        // Si no se proporciona trace_id, intentar obtenerlo del contexto de la request
        if (!$traceId && request()->hasHeader('X-Trace-ID')) {
            $traceId = request()->header('X-Trace-ID');
        }

        // Agregar metadata de contexto
        $contextMetadata = [
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_id' => request()->header('X-Request-ID'),
        ];

        $metadata = array_merge($contextMetadata, $metadata ?: []);

        return ActivityLog::create([
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'user_id'     => $userId,
            'trace_id'    => $traceId,
            'metadata'    => $metadata,
            'description' => $description,
            'created_at'  => now(),
        ]);
    }

    public function logUserAction(
        string $action,
        ?string $description = null,
        ?array $metadata = []
    ): ActivityLog {
        return $this->log(
            action: $action,
            description: $description,
            metadata: $metadata
        );
    }

    public function logEntityAction(
        string $action,
        string $entityType,
        string $entityId,
        ?string $description = null,
        ?array $metadata = []
    ): ActivityLog {
        return $this->log(
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            description: $description,
            metadata: $metadata
        );
    }

    public function logSecurityAction(
        string $action,
        ?string $description = null,
        ?array $metadata = []
    ): ActivityLog {
        return $this->log(
            action: "security.{$action}",
            description: $description,
            metadata: array_merge($metadata ?: [], [
                'security_context' => true,
            ])
        );
    }
}
