<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Dashboard\Contracts\DashboardCache;
use App\Infrastructure\Cache\RedisDashboardCache;
use Tests\Core\Contracts\DashboardCacheContract;
use Tests\Integration\Support\UsesDashboardRedis;
use Tests\Integration\Support\UsesMysql;

final class RedisDashboardCacheContractTest extends DashboardCacheContract
{
    use UsesDashboardRedis;
    use UsesMysql { setUp as private databaseSetup;
        tearDown as private databaseCleanup; }

    protected function setUp(): void
    {
        $this->databaseSetup();
        $this->prepareRedis();
    }

    protected function tearDown(): void
    {
        $this->cleanupRedis();
        $this->databaseCleanup();
    }

    protected function cache(): DashboardCache
    {
        return new RedisDashboardCache;
    }

    public function test_values_have_bounded_lifetime_and_only_one_revision_is_retained(): void
    {
        $cache = $this->cache();
        for ($i = 0; $i < 30; $i++) {
            $cache->put($this->data(revision: (string) $i));
        }
        $keys = $this->cacheProbe->keys($this->cachePrefix.'dashboard:*');
        self::assertCount(2, $keys);
        foreach ($keys as $key) {
            self::assertGreaterThan(0, $this->cacheProbe->ttl($key));
            self::assertLessThanOrEqual(RedisDashboardCache::TTL, $this->cacheProbe->ttl($key));
        }
    }

    public function test_malformed_or_wrong_identity_cached_payload_is_a_miss(): void
    {
        $key = $this->cachePrefix.'dashboard:v1:{7}:revision:2';
        foreach (['{broken', json_encode(['ownerUserId' => '8', 'revision' => '2', 'incomeMinor' => '0', 'expenseMinor' => '0', 'balanceMinor' => '0', 'transactionCount' => '0']),
            json_encode(['ownerUserId' => '7', 'revision' => '2', 'incomeMinor' => '0', 'expenseMinor' => '0', 'balanceMinor' => '1', 'transactionCount' => '0'])] as $payload) {
            $this->cacheProbe->set($key, $payload);
            self::assertNull($this->cache()->get('7', '2'));
        }
    }
}
