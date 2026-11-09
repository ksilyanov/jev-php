<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Ksilyanov\Jev\Contracts\Jev;
use Ksilyanov\Jev\Drivers\CloudflareDriver;
use Ksilyanov\Jev\Laravel\JevServiceProvider;
use PHPUnit\Framework\TestCase;

final class JevServiceProviderTest extends TestCase
{
    private const VARS = ['JEV_DRIVER', 'JEV_MODEL', 'JEV_CLOUDFLARE_ACCOUNT_ID', 'JEV_CLOUDFLARE_API_TOKEN'];

    protected function tearDown(): void
    {
        foreach (self::VARS as $var) {
            unset($_ENV[$var], $_SERVER[$var]);
        }
    }

    private function resolve(array $env): Jev
    {
        foreach ($env as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = new Container();
        $app->instance('config', new Repository());
        (new JevServiceProvider($app))->register();

        return $app->make(Jev::class);
    }

    public function test_builds_cloudflare_driver_from_env_with_default_model(): void
    {
        $jev = $this->resolve(['JEV_CLOUDFLARE_ACCOUNT_ID' => 'acc', 'JEV_CLOUDFLARE_API_TOKEN' => 'tok']);

        $this->assertInstanceOf(CloudflareDriver::class, $jev);
        $this->assertSame('typesafe/jev', $jev->model());
    }

    public function test_model_override(): void
    {
        $this->assertSame('typesafe/jev-next', $this->resolve(['JEV_MODEL' => 'typesafe/jev-next'])->model());
    }

    public function test_unknown_driver_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolve(['JEV_DRIVER' => 'nope']);
    }
}
