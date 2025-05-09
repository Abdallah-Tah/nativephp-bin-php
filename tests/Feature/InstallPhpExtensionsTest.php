<?php

namespace Amohamed\NativePhpCustomPhp\Tests\Feature;

use Amohamed\NativePhpCustomPhp\Commands\InstallPhpExtensions;
use Orchestra\Testbench\TestCase;

class InstallPhpExtensionsTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return ['Amohamed\NativePhpCustomPhp\NativePhpCustomPhpServiceProvider'];
    }

    public function test_command_exists()
    {
        $this->assertTrue(class_exists(InstallPhpExtensions::class));
        $this->artisan('php-ext:install --help')
            ->assertExitCode(0);
    }

    public function test_os_detection()
    {
        $command = $this->app->make(InstallPhpExtensions::class);
        $method = new \ReflectionMethod($command, 'detectOS');
        $method->setAccessible(true);

        $os = $method->invoke($command);
        $this->assertContains($os, ['Windows', 'Linux', 'macOS']);
    }

    public function test_environment_validation()
    {
        $this->artisan('php-ext:install')
            ->expectsQuestion('Which PHP extensions would you like to install?', [])
            ->assertExitCode(0);
    }
}