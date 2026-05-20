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
    protected function defineEnvironment($app)
    {
        $app['config']->set('broadcasting.default', 'log');
    }
}

uses(BaseTestCase::class)->in(__DIR__);
