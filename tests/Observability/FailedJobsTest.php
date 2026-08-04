<?php
use Innertia\Observability\Queues\FailedJobs;

it('mapea un job fallido: nombre del payload + primera línea de la excepción', function () {
    $row = (object) [
        'uuid' => 'u-1', 'connection' => 'redis', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\ChargeMember']),
        'exception' => "RuntimeException: boom\n#0 /app/foo.php(1)",
        'failed_at' => '2026-08-03 10:00:00',
    ];
    $m = FailedJobs::map($row);
    expect($m['id'])->toBe('u-1');
    expect($m['name'])->toBe('App\\Jobs\\ChargeMember');
    expect($m['exception'])->toBe('RuntimeException: boom');
    expect($m['queue'])->toBe('default');
});

it('list no lanza y devuelve un array', function () {
    expect(FailedJobs::list())->toBeArray();
});
