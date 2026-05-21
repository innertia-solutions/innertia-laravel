<?php

namespace Innertia\Devtools\Tinker;

use Illuminate\Support\Facades\Log;

class TinkerAuditLog
{
    /**
     * Writes an audit entry to the default log channel.
     * Every remote eval must be logged before execution.
     */
    public static function record(string $sessionId, string $code, ?string $ip): void
    {
        Log::info('[devtools:tinker]', [
            'session_id' => $sessionId,
            'ip'         => $ip,
            'app'        => config('app.name'),
            'code'       => $code,
            'at'         => now()->toISOString(),
        ]);
    }
}
