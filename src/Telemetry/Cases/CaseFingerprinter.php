<?php

namespace Innertia\Telemetry\Cases;

class CaseFingerprinter
{
    public function for(string $class, string $file, int $line, string $message): string
    {
        return hash('sha256', implode('|', [
            $class,
            $file,
            $line,
            $this->normalizeMessage($message),
        ]));
    }

    public function normalizeMessage(string $message): string
    {
        // Reemplaza números, uuids y comillas por marcadores para agrupar mensajes equivalentes.
        $m = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $message);
        $m = preg_replace('/\d+/', '{n}', $m);
        $m = preg_replace('/[\'"][^\'"]*[\'"]/', '{str}', $m);
        return trim((string) $m);
    }
}
