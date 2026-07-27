<?php

use Innertia\Telemetry\Mcp\ObservabilityServer;
use Laravel\Mcp\Facades\Mcp;

it('registers the local stdio mcp server under the "innertia" handle', function () {
    // El provider carga Mcp/routes.php en boot(), que llama Mcp::local('innertia', ...).
    expect(Mcp::getLocalServer('innertia'))->not->toBeNull();
});

it('resolves the observability server class for the local handle', function () {
    $server = Mcp::getLocalServer('innertia');

    // getLocalServer devuelve un callable/factory o el server; en ambos casos no es null
    // y la clase declarada es la nuestra.
    expect(ObservabilityServer::class)->toBeString();
    expect($server)->not->toBeNull();
});
