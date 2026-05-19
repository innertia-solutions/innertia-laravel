<?php

return [
    'enabled'         => env('TELEMETRY_ENABLED', false),
    'app_name'        => env('APP_NAME', 'app'),
    'olimpo_url'      => env('OLIMPO_URL'),
    'olimpo_key'      => env('OLIMPO_KEY'),
    'queue'           => env('TELEMETRY_QUEUE', 'telemetry'),
    'timeout'         => 3,
    'retention_days'  => env('TELEMETRY_RETENTION_DAYS', 7),
    'capture' => [
        'queries'     => true,
        'logs'        => true,
        'exceptions'  => true,
        'datatables'  => true,
        'events'      => true,
        'requests'    => true,
    ],
    'except' => [
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],
];
