<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

trait UsesDashboardRedis
{
    private ?\Redis $cacheProbe = null;

    private string $cachePrefix = '';

    private function prepareRedis(): void
    {
        if (getenv('REDIS_TEST_ENABLED') !== '1') {
            throw new RuntimeException('Disposable Redis must be explicitly enabled.');
        }
        $host = getenv('REDIS_TEST_HOST') ?: 'redis-test';
        $port = (int) (getenv('REDIS_TEST_PORT') ?: 6379);
        if (! in_array($host, ['127.0.0.1', 'redis-test'], true)) {
            throw new RuntimeException('Disposable Redis host required.');
        }
        $this->cachePrefix = 'finance_ledger_test:'.bin2hex(random_bytes(12)).':';
        config(['database.redis.options.prefix' => $this->cachePrefix]);
        foreach (['dashboard', 'default', 'outbox'] as $name) {
            config(['database.redis.'.$name.'.host' => $host, 'database.redis.'.$name.'.port' => $port,
                'database.redis.'.$name.'.url' => null, 'database.redis.'.$name.'.username' => null, 'database.redis.'.$name.'.password' => null,
                'database.redis.'.$name.'.database' => $name === 'dashboard' ? 1 : 0]);
            Redis::purge($name);
        }
        config(['queue.connections.redis.block_for' => null]);
        $this->cacheProbe = new \Redis;
        $this->cacheProbe->connect($host, $port, 2);
        $this->cacheProbe->select(1);
    }

    private function reloadRedisConfiguration(): void
    {
        // RedisManager snapshots its configuration when instantiated; purge alone reuses it.
        foreach (['dashboard', 'default', 'outbox'] as $name) {
            Redis::purge($name);
        }
        Redis::clearResolvedInstance('redis');
        $this->app->forgetInstance('redis');
    }

    private function cleanupRedis(): void
    {
        if ($this->cacheProbe === null) {
            return;
        }
        foreach ([0, 1] as $db) {
            $this->cacheProbe->select($db);
            foreach ($this->cacheProbe->keys($this->cachePrefix.'*') as $key) {
                $this->cacheProbe->del($key);
            }
        }
        $this->cacheProbe->close();
    }
}
