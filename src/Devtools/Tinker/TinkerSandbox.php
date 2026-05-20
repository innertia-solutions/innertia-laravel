<?php

namespace Innertia\Devtools\Tinker;

class TinkerSandbox
{
    private const BLOCKED = [
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'popen',
        'proc_open',
        'pcntl_exec',
        'file_put_contents',
        'file_get_contents',
        'unlink',
        'rmdir',
        'chmod',
        'chown',
        'rename',
        'copy',
        'mkdir',
    ];

    /**
     * @throws \RuntimeException when the code references a blocked function
     */
    public static function validate(string $code): void
    {
        foreach (self::BLOCKED as $fn) {
            // Negative lookbehinds prevent false positives on method calls (->exec, ::exec)
            // and also catch namespace-qualified calls (\exec) which bypass plain \b anchors.
            if (preg_match('/(?<!->)(?<!::)(?<!\\\\)\b' . preg_quote($fn, '/') . '\s*\(/i', $code)) {
                throw new \RuntimeException(
                    "Function '{$fn}' is not allowed in remote Tinker sessions."
                );
            }
        }
    }
}
