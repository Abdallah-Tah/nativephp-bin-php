<?php

namespace Amohamed\NativePhpCustomPhp\Tests\Unit;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            'Amohamed\NativePhpCustomPhp\NativePhpCustomPhpServiceProvider'
        ];
    }
}