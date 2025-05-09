<?php

namespace Amohamed\NativePhpCustomPhp;

use Illuminate\Support\ServiceProvider;

class NativePhpCustomPhpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallPhpExtensions::class,
            ]);

            // Publish configuration file
            $this->publishes([
                __DIR__ . '/../config/nativephp-custom-php.php' => config_path('nativephp-custom-php.php'),
            ], 'config');
        }
    }
}