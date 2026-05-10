<?php

if (! function_exists('traceId')) {
    function traceId(): string
    {
        try {
            return app('trace_id') ?? '';
        } catch (\Throwable) {
            return '';
        }
    }
}
