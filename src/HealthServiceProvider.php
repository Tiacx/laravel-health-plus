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

        $checks = [];

        foreach (config('health.checks', []) as $checkConfig) {
            !is_array($checkConfig) && $checkConfig = [$checkConfig];
            $methods = isset($checkConfig[1]) ? $checkConfig[1] : [];
            $frequency = isset($checkConfig[2]) ? $checkConfig[2] : null;
            $check = app($checkConfig[0]);
            foreach ($methods as $method => $args) {
                call_user_func_array([$check, $method], is_array($args) ? $args : [$args]);
            }
            !is_null($frequency) && call_user_func_array([$check, $frequency], []);
            $checks[] = $check;
        }

        Health::checks($checks);
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
