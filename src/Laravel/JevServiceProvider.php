<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Laravel;

use Illuminate\Support\ServiceProvider;
use Ksilyanov\Jev\Contracts\Jev;
use Ksilyanov\Jev\Drivers\CloudflareDriver;
use Ksilyanov\Jev\Http\RetryPolicy;

/** Auto-discovered in Laravel. Laravel Zero ignores discovery: add it to config/app.php providers. */
final class JevServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/jev.php', 'jev');

        $this->app->singleton(Jev::class, static function ($app): Jev {
            $config = $app['config']['jev'];

            return match ($config['driver']) {
                'cloudflare' => new CloudflareDriver(
                    accountId: (string) $config['drivers']['cloudflare']['account_id'],
                    apiToken: (string) $config['drivers']['cloudflare']['api_token'],
                    model: (string) ($config['model'] ?? CloudflareDriver::DEFAULT_MODEL),
                    timeout: (float) $config['timeout'],
                    retry: new RetryPolicy(maxRetries: (int) $config['retries']),
                    gatewayId: $config['drivers']['cloudflare']['gateway_id'] ?? null,
                ),
                default => throw new \InvalidArgumentException("Unknown JEV_DRIVER '{$config['driver']}'"),
            };
        });
    }

    public function boot(): void
    {
        if (method_exists($this->app, 'configPath')) {
            $this->publishes([__DIR__ . '/../../config/jev.php' => $this->app->configPath('jev.php')], 'jev-config');
        }
    }
}
