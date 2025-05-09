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
        }
    }
}