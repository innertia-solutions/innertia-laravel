<?php

/*
|--------------------------------------------------------------------------
| JWT — codec propio (firebase/php-jwt)
|--------------------------------------------------------------------------
|
| Innertia firma/verifica sus tokens con firebase/php-jwt directamente
| (sin tymon/jwt-auth). El guard, las sesiones y la revocación son propios.
|
|   secret — clave de firma. Generar con `php artisan jwt:secret`.
|   ttl    — minutos de vigencia del token.
|   algo   — algoritmo de firma (HS256 por defecto).
|
*/

return [
    'secret' => env('JWT_SECRET'),
    'ttl'    => (int) env('JWT_TTL', 60),
    'algo'   => env('JWT_ALGO', 'HS256'),
];
