<?php

namespace Amohamed\NativePhpCustomPhp;

use Amohamed\NativePhpCustomPhp\Commands\InstallPhpExtensions;
use Illuminate\Support\ServiceProvider;

class NativePhpCustomPhpServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/nativephp-custom-php.php',
            'nativephp-custom-php'
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallPhpExtensions::class,
            ]);

            $this->publishes([
                __DIR__ . '/config/nativephp-custom-php.php' => config_path('nativephp-custom-php.php'),
            ], 'nativephp-custom-php-config');
        }
    }

    public function boot()
    {
        // Make sure the config directory exists
        if (!is_dir(config_path())) {
            mkdir(config_path(), 0755, true);
        }
    }
}
