<?php
use Illuminate\Http\Request;
use Innertia\Telemetry\Cases\CaseSnapshot;

it('enriquece el request con query/headers/body sanitizados', function () {
    config(['innertia.telemetry.redact' => ['password', 'authorization']]);
    $req = Request::create('/pay?amount=100', 'POST', ['card' => '4111', 'password' => 'secret']);
    $req->headers->set('Authorization', 'Bearer abc');
    $req->headers->set('User-Agent', 'PomelyTest/1.0');
    app()->instance('request', $req);

    $snap = CaseSnapshot::build(new \RuntimeException('x'));
    $r = $snap['request'];

    expect($r)->not->toBeNull();
    expect($r['query']['amount'])->toBe('100');
    expect($r['body']['card'])->toBe('4111');
    expect($r['body']['password'])->toBe('[REDACTED]');
    $auth = $r['headers']['authorization'] ?? null;
    expect(is_array($auth) ? ($auth[0] ?? null) : $auth)->toBe('[REDACTED]');
    expect($r['user_agent'])->toBe('PomelyTest/1.0');
});
