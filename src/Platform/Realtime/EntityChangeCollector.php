<?php

namespace Innertia\Platform\Realtime;

use Innertia\Platform\Events\EntityChanged;

/**
 * Acumula cambios de entidad por request y los emite COALESCIDOS: un solo
 * EntityChanged por tabla al flush (evita 1000 broadcasts en un bulk de 1000 filas).
 * Registrado como singleton; el flush se dispara en app()->terminating() (tras enviar
 * la respuesta), o manualmente (tests / comandos).
 */
class EntityChangeCollector
{
    /** @var array<string, array{ids: array<string,true>, actions: array<string,true>, private: bool}> */
    private array $buffer = [];

    private bool $flushRegistered = false;

    private const ID_CAP = 100;

    public function record(string $table, string $action, mixed $id, bool $private = false): void
    {
        if (! isset($this->buffer[$table])) {
            $this->buffer[$table] = ['ids' => [], 'actions' => [], 'private' => $private];
        }
        if ($id !== null && $id !== '') {
            $this->buffer[$table]['ids'][(string) $id] = true;
        }
        $this->buffer[$table]['actions'][$action] = true;
        $this->buffer[$table]['private'] = $this->buffer[$table]['private'] || $private;

        $this->registerFlush();
    }

    public function touch(string $table, array $ids = [], string $action = 'updated', bool $private = false): void
    {
        if (empty($ids)) {
            $this->record($table, $action, null, $private);

            return;
        }
        foreach ($ids as $id) {
            $this->record($table, $action, $id, $private);
        }
    }

    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];

        foreach ($buffer as $table => $entry) {
            $ids = array_slice(array_keys($entry['ids']), 0, self::ID_CAP);
            EntityChanged::dispatch(
                table: $table,
                ids: $ids,
                actions: array_keys($entry['actions']),
                private: $entry['private'],
            );
        }
    }

    private function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }
        $this->flushRegistered = true;
        // Se ejecuta tras enviar la respuesta (no bloquea al cliente).
        app()->terminating(function () {
            $this->flush();
            $this->flushRegistered = false;
        });
    }
}
