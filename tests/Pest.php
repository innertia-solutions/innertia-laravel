<?php

use Orchestra\Testbench\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class and all PHPUnit lifecycle hooks (setUp, tearDown, etc.) are called
| automatically by PHPUnit.
|
*/

class BaseTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Laravel\Socialite\SocialiteServiceProvider::class,
            // En apps reales laravel/mcp se auto-descubre; en Testbench hay que
            // registrarlo a mano para que el Request de MCP reciba sus argumentos.
            \Laravel\Mcp\Server\McpServiceProvider::class,
            \Innertia\InnertiaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('broadcasting.default', 'log');
        // Generate a 32-byte key for AES-256-CBC
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
        // The Payments engine is opt-in (default OFF). Enable it here — before the
        // package providers boot — so PaymentsServiceProvider::boot() loads the
        // charges/payments migrations and the Payments test suite finds its tables.
        $app['config']->set('innertia.payments.enabled', true);
        $app['config']->set('innertia.ai.enabled', true);
    }
}

uses(BaseTestCase::class)->in(__DIR__);
