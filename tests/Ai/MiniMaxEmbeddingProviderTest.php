<?php

use Illuminate\Support\Facades\Http;
use Innertia\Ai\Embeddings\MiniMaxEmbeddingProvider;

it('devuelve vectores en una respuesta exitosa', function () {
    Http::fake([
        '*/embeddings' => Http::response(['vectors' => [[0.1, 0.2, 0.3]], 'base_resp' => ['status_code' => 0, 'status_msg' => 'success']], 200),
    ]);

    $provider = new MiniMaxEmbeddingProvider('k', 'embo-01', 'https://api.minimax.io/v1', 3);

    expect($provider->embedQuery('hola'))->toBe([0.1, 0.2, 0.3]);
});

it('lanza cuando MiniMax devuelve un error lógico en base_resp (HTTP 200)', function () {
    // MiniMax envuelve rate limit / errores con HTTP 200 + base_resp.status_code != 0.
    Http::fake([
        '*/embeddings' => Http::response(['vectors' => null, 'base_resp' => ['status_code' => 1002, 'status_msg' => 'rate limit exceeded(RPM)']], 200),
    ]);

    $provider = new MiniMaxEmbeddingProvider('k', 'embo-01', 'https://api.minimax.io/v1', 3);

    expect(fn () => $provider->embedQuery('hola'))
        ->toThrow(RuntimeException::class, 'rate limit exceeded(RPM)');
});

it('lanza cuando la respuesta no trae vectores', function () {
    Http::fake([
        '*/embeddings' => Http::response(['base_resp' => ['status_code' => 0]], 200),
    ]);

    $provider = new MiniMaxEmbeddingProvider('k', 'embo-01', 'https://api.minimax.io/v1', 3);

    expect(fn () => $provider->embedQuery('hola'))->toThrow(RuntimeException::class);
});
