<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Olimpo API Key
    |--------------------------------------------------------------------------
    | La api_key generada en el panel de Olimpo para esta aplicación.
    | Se valida en cada request entrante via header X-Olimpo-Key.
    */
    'key' => env('OLIMPO_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Olimpo URL
    |--------------------------------------------------------------------------
    | URL base de la instancia de Olimpo. Se usa para enviar exceptions.
    | Ejemplo: http://olimpo-backend:8000 (red interna Dokploy)
    */
    'url' => env('OLIMPO_URL'),

    /*
    |--------------------------------------------------------------------------
    | Exception reporting
    |--------------------------------------------------------------------------
    | Clases de excepciones que NO se reportarán a Olimpo.
    */
    'except' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],
];
