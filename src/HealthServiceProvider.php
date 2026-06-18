<?php

namespace Tiacx\Health;

use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Facades\Health;

class HealthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        if (App::environment('testing')) {
            return;
        }

        Health::checks(config('health.checks', []));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/health.php' => config_path('health.php'),
        ], 'health-plus-config');
    }
}
