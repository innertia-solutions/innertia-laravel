<?php

namespace Innertia\Platform\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Evento genérico de cambio de entidad, scopeado por TABLA física.
 * Canal: entity.{table} (público por defecto). Evento: entity.{table}.changed.
 * Payload MÍNIMO {table, ids, actions} — nunca el registro.
 * ShouldBroadcastNow: no depende de un queue worker (igual que EvaluationUpdated).
 */
class EntityChanged extends DomainEvent implements ShouldBroadcastNow
{
    /**
     * @param string   $table    nombre físico de la tabla (getTable()).
     * @param string[] $ids      ids afectados (coalescidos), tope defensivo aplicado por el colector.
     * @param string[] $actions  acciones deduplicadas: created|updated|deleted|restored.
     * @param bool     $private  canal privado-scopeado (opt-in).
     */
    public function __construct(
        public readonly string $table,
        public readonly array $ids = [],
        public readonly array $actions = [],
        public readonly bool $private = false,
    ) {}

    public function key(): DomainEventKey
    {
        return EntityEventKey::Changed;
    }

    public function channels(): array
    {
        return ['realtime'];
    }

    public function channel(): Channel
    {
        $name = 'entity.'.$this->table;

        return $this->private ? new PrivateChannel($name) : new Channel($name);
    }

    public function broadcastAs(): string
    {
        return 'entity.'.$this->table.'.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'table'   => $this->table,
            'ids'     => $this->ids,
            'actions' => array_values(array_unique($this->actions)),
        ];
    }
}
