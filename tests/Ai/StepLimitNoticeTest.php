<?php

use Innertia\Ai\Assistant\LaravelAiModel;

it('devuelve el texto del modelo cuando lo hay', function () {
    expect(LaravelAiModel::textOrStepLimitNotice((object) ['text' => ' Listo. ', 'toolCalls' => []]))->toBe('Listo.');
});

it('con texto vacío avisa del límite de pasos y resume las herramientas usadas', function () {
    $notice = LaravelAiModel::textOrStepLimitNotice((object) [
        'text' => '',
        'toolCalls' => [['name' => 'searchCatalog'], ['name' => 'searchCatalog'], (object) ['name' => 'createQuestion']],
    ]);

    expect($notice)->toContain('límite de pasos')
        ->toContain('searchCatalog ×2')
        ->toContain('createQuestion ×1')
        ->toContain('continúe');
});
