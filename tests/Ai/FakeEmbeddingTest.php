<?php

use Innertia\Ai\Embeddings\FakeEmbeddingProvider;

// No DB needed — FakeEmbeddingProvider is pure PHP, no HTTP, no Eloquent.

it('produces a deterministic vector of the requested dimension', function () {
    $e = new FakeEmbeddingProvider(8);
    $a = $e->embedQuery('hola');
    $b = $e->embedQuery('hola');

    expect(count($a))->toBe(8)
        ->and($a)->toBe($b)
        ->and($e->embedQuery('chao'))->not->toBe($a);
});

it('embedDocuments returns one vector per input of the right dimension', function () {
    $v = (new FakeEmbeddingProvider(4))->embedDocuments(['a', 'b', 'c']);

    expect(count($v))->toBe(3);

    foreach ($v as $vec) {
        expect(count($vec))->toBe(4);
    }
});

it('dimension() reports the configured size', function () {
    expect((new FakeEmbeddingProvider(123))->dimension())->toBe(123);
});
