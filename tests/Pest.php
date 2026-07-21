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
            \Innertia\InnertiaServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('broadcasting.default', 'log');
        // Generate a 32-byte key for AES-256-CBC
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }
}

uses(BaseTestCase::class)->in(__DIR__);
