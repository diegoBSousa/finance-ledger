<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class EnvironmentIsolationTest extends TestCase
{
    public function test_framework_tests_use_isolated_services_even_with_container_environment_variables(): void
    {
        self::assertTrue($this->app->environment('testing'));
        self::assertFalse(config('app.debug'));
        self::assertInstanceOf(ArrayStore::class, Cache::getStore());
        self::assertSame('sync', config('queue.default'));
        self::assertSame('array', config('session.driver'));
        self::assertSame('array', config('mail.default'));
        self::assertSame('null', config('logging.default'));
    }

    public function test_rate_limit_counters_do_not_leak_between_application_instances(): void
    {
        // Refuse to write a counter to a persistent store if isolation regresses.
        self::assertInstanceOf(ArrayStore::class, Cache::getStore());
        RateLimiter::hit('test:login-isolation', 60);
        self::assertSame(1, RateLimiter::attempts('test:login-isolation'));

        $this->refreshApplication();

        self::assertSame(0, RateLimiter::attempts('test:login-isolation'));
    }
}
