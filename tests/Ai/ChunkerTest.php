<?php

use Innertia\Ai\Services\Chunker;

it('returns a single chunk for short text', function () {
    $chunks = (new Chunker(1200))->chunk('hola mundo');

    expect($chunks)->toBe(['hola mundo']);
});

it('returns empty array for blank text', function () {
    $chunks = (new Chunker)->chunk('   ');

    expect($chunks)->toBe([]);
});

it('splits long text into multiple chunks within the max size', function () {
    $text = str_repeat('palabra ', 800); // ~6400 chars
    $chunks = (new Chunker(1000, 100))->chunk($text);

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(1000);
    }
});

it('keeps paragraphs together when they fit', function () {
    $text = "Primer párrafo con contenido corto.\n\nSegundo párrafo también corto.";
    $chunks = (new Chunker(5000))->chunk($text);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toContain('Primer párrafo')
        ->and($chunks[0])->toContain('Segundo párrafo');
});
