<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Dashboard\CacheUnavailable;
use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Data\DashboardData;
use App\Application\Dashboard\FinancialRevision;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use JsonException;
use Throwable;

final class RedisDashboardCache implements DashboardCache
{
    public const int TTL = 300;

    public function get(string $ownerUserId, string $revision): ?DashboardData
    {
        try {
            $json = $this->connection()->get($this->key($ownerUserId, $revision));
        } catch (Throwable) {
            throw new CacheUnavailable;
        }
        if (! is_string($json)) {
            return null;
        }
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            foreach (['ownerUserId', 'revision', 'incomeMinor', 'expenseMinor', 'balanceMinor', 'transactionCount'] as $field) {
                if (! is_array($data) || ! is_string($data[$field] ?? null)) {
                    return null;
                }
            }
            $result = new DashboardData($data['ownerUserId'], $data['revision'], $data['incomeMinor'], $data['expenseMinor'], $data['balanceMinor'], $data['transactionCount']);

            return $result->ownerUserId === $ownerUserId && $result->revision === $revision ? $result : null;
        } catch (JsonException|DomainViolation) {
            return null;
        }
    }

    public function put(DashboardData $data): void
    {
        $script = <<<'LUA'
            if (redis.call('GET', KEYS[1]) or '') ~= ARGV[1] then return 0 end
            redis.call('SET', KEYS[2], ARGV[3], 'EX', ARGV[4])
            redis.call('SET', KEYS[1], ARGV[2], 'EX', ARGV[4])
            if KEYS[3] ~= KEYS[2] then redis.call('DEL', KEYS[3]) end
            return 1
            LUA;
        try {
            $redis = $this->connection();
            $head = $this->head($data->ownerUserId);
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $previous = $redis->get($head);
                if ($previous !== null) {
                    FinancialRevision::validate($previous);
                    if (FinancialRevision::compare($previous, $data->revision) > 0) {
                        return;
                    }
                }
                $key = $this->key($data->ownerUserId, $data->revision);
                if ($redis->eval($script, 3, $head, $key, $this->key($data->ownerUserId, $previous ?? $data->revision),
                    $previous ?? '', $data->revision, json_encode($data, JSON_THROW_ON_ERROR), self::TTL) === 1) {
                    return;
                }
            }
            throw new CacheUnavailable;
        } catch (Throwable) {
            throw new CacheUnavailable;
        }
    }

    public function invalidate(string $ownerUserId, string $revision): void
    {
        $script = <<<'LUA'
            if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
            redis.call('DEL', KEYS[1], KEYS[2])
            return 1
            LUA;
        try {
            FinancialRevision::validate($revision);
            $redis = $this->connection();
            $head = $this->head($ownerUserId);
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $previous = $redis->get($head);
                if ($previous === null) {
                    return;
                }
                FinancialRevision::validate($previous);
                if (FinancialRevision::compare($previous, $revision) >= 0) {
                    return;
                }
                if ($redis->eval($script, 2, $head, $this->key($ownerUserId, $previous), $previous) === 1) {
                    return;
                }
            }
            throw new CacheUnavailable;
        } catch (Throwable) {
            throw new CacheUnavailable;
        }
    }

    private function connection(): PhpRedisConnection
    {
        $connection = Redis::connection('dashboard');
        if (! $connection instanceof PhpRedisConnection) {
            throw new CacheUnavailable;
        }

        return $connection;
    }

    private function head(string $owner): string
    {
        if ((string) DecimalInteger::positive($owner) !== $owner) {
            throw new CacheUnavailable;
        }

        return 'dashboard:v1:{'.$owner.'}:head';
    }

    private function key(string $owner, string $revision): string
    {
        $this->head($owner);

        return 'dashboard:v1:{'.$owner.'}:revision:'.FinancialRevision::validate($revision);
    }
}
