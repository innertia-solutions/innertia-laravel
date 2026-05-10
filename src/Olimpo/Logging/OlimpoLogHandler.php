<?php

namespace Innertia\Olimpo\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class OlimpoLogHandler extends AbstractProcessingHandler
{
    private const CACHE_KEY = 'olimpo_logs_buffer';
    private const MAX_ENTRIES = 200;

    public function __construct(int|string|Level $level = Level::Error)
    {
        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        $entry = [
            'level'   => $record->level->name,
            'message' => $record->message,
            'context' => $this->sanitizeContext($record->context),
            'time'    => $record->datetime->format('Y-m-d H:i:s'),
        ];

        $buffer = cache()->get(self::CACHE_KEY, []);
        $buffer[] = $entry;

        // Mantener solo los últimos MAX_ENTRIES
        if (count($buffer) > self::MAX_ENTRIES) {
            $buffer = array_slice($buffer, -self::MAX_ENTRIES);
        }

        cache()->put(self::CACHE_KEY, $buffer, now()->addHours(2));
    }

    public static function flush(): array
    {
        $buffer = cache()->get(self::CACHE_KEY, []);
        cache()->forget(self::CACHE_KEY);
        return $buffer;
    }

    public static function peek(): array
    {
        return cache()->get(self::CACHE_KEY, []);
    }

    private function sanitizeContext(array $context): array
    {
        // Eliminar excepciones complejas y limitar tamaño
        return collect($context)
            ->map(fn ($v) => $v instanceof \Throwable
                ? ['exception' => get_class($v), 'message' => $v->getMessage(), 'file' => $v->getFile(), 'line' => $v->getLine()]
                : $v
            )
            ->toArray();
    }
}
