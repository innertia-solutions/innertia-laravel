<?php
use Innertia\Telemetry\Cases\ErrorClassifier;
it('clasifica category por tipo de excepción', function () {
    expect(ErrorClassifier::category(new \RuntimeException('x')))->toBe('runtime');
    expect(ErrorClassifier::category(new \Illuminate\Validation\ValidationException(validator([], []))))->toBe('validation');
    expect(ErrorClassifier::category(new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException()))->toBe('http');
});
it('origin devuelve un valor conocido sin lanzar', function () {
    expect(ErrorClassifier::origin(new \RuntimeException('x')))->toBeIn(['http', 'queue', 'schedule', 'console']);
});
