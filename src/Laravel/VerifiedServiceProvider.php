<?php

declare(strict_types=1);

namespace VerifiedNet\Laravel;

use Illuminate\Support\ServiceProvider;
use VerifiedNet\Client;

/** Registers a shared Client built from config/verified.php. Auto-discovered by Laravel. */
class VerifiedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/verified.php', 'verified');

        $this->app->singleton(Client::class, static function ($app): Client {
            $config = $app['config']['verified'];
            return new Client($config['key'] ?: null, [
                'base_url' => $config['base_url'],
                'timeout' => $config['timeout'],
                'max_retries' => 0,   // a form shouldn't wait for retries
            ]);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/verified.php' => config_path('verified.php')], 'verified-config');
        }
    }
}
