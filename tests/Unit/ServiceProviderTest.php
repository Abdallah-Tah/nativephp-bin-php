<?php

namespace Amohamed\NativePhpCustomPhp\Tests\Unit;

use Amohamed\NativePhpCustomPhp\NativePhpCustomPhpServiceProvider;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [NativePhpCustomPhpServiceProvider::class];
    }

    public function test_service_provider_is_registered()
    {
        $this->assertTrue($this->app->providerIsLoaded(NativePhpCustomPhpServiceProvider::class));
    }
}