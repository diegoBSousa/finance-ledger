<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Dashboard\CacheUnavailable;
use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Contracts\DashboardInvalidationRepository;
use App\Application\Dashboard\Contracts\DashboardRepository;
use App\Application\Dashboard\Data\DashboardData;
use App\Application\Dashboard\Data\DashboardInvalidationData;
use App\Application\Dashboard\Data\GetDashboardRequest;
use App\Application\Dashboard\Data\InvalidateDashboardRequest;
use App\Application\Dashboard\GetDashboardUseCase;
use App\Application\Dashboard\InvalidateDashboardUseCase;
use App\Application\Shared\ReadUnavailable;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\InMemoryDashboardCache;

final class DashboardUseCasesTest extends TestCase
{
    public function test_cache_hit_requires_current_sql_revision_and_skips_aggregate(): void
    {
        $repo = $this->createMock(DashboardRepository::class);
        $repo->expects(self::once())->method('revision')->with('7')->willReturn('2');
        $repo->expects(self::never())->method('snapshot');
        $cache = new InMemoryDashboardCache;
        $data = new DashboardData('7', '2', '10', '2', '8', '2');
        $cache->put($data);
        self::assertSame($data, (new GetDashboardUseCase($repo, $cache))->execute(new GetDashboardRequest('7'))->dashboard);
    }

    public function test_snapshot_that_advances_during_miss_is_cached_under_its_own_revision(): void
    {
        $repo = $this->createStub(DashboardRepository::class);
        $repo->method('revision')->willReturn('2');
        $data = new DashboardData('7', '4', '10', '20', '-10', '2');
        $repo->method('snapshot')->willReturn($data);
        $cache = new InMemoryDashboardCache;
        self::assertSame($data, (new GetDashboardUseCase($repo, $cache))->execute(new GetDashboardRequest('7'))->dashboard);
        self::assertNull($cache->get('7', '2'));
        self::assertSame($data, $cache->get('7', '4'));
    }

    public function test_cache_read_and_write_failures_keep_valid_sql_response(): void
    {
        $repo = $this->createStub(DashboardRepository::class);
        $repo->method('revision')->willReturn('0');
        $data = new DashboardData('7', '0', '0', '0', '0', '0');
        $repo->method('snapshot')->willReturn($data);
        $cache = $this->createMock(DashboardCache::class);
        $cache->method('get')->willThrowException(new CacheUnavailable);
        $cache->expects(self::once())->method('put')->willThrowException(new CacheUnavailable);
        self::assertSame($data, (new GetDashboardUseCase($repo, $cache))->execute(new GetDashboardRequest('7'))->dashboard);
    }

    public function test_sql_failure_does_not_return_an_old_cache_or_zero(): void
    {
        $repo = $this->createStub(DashboardRepository::class);
        $repo->method('revision')->willThrowException(new ReadUnavailable);
        $cache = $this->createMock(DashboardCache::class);
        $cache->expects(self::never())->method('get');
        $this->expectException(ReadUnavailable::class);
        (new GetDashboardUseCase($repo, $cache))->execute(new GetDashboardRequest('7'));
    }

    public function test_wrong_owner_or_revision_from_cache_is_treated_as_miss(): void
    {
        $repo = $this->createMock(DashboardRepository::class);
        $repo->method('revision')->willReturn('2');
        $data = new DashboardData('7', '2', '4', '2', '2', '2');
        $repo->expects(self::once())->method('snapshot')->willReturn($data);
        $cache = $this->createStub(DashboardCache::class);
        $cache->method('get')->willReturn(new DashboardData('8', '2', '0', '0', '0', '0'));
        self::assertSame($data, (new GetDashboardUseCase($repo, $cache))->execute(new GetDashboardRequest('7'))->dashboard);
    }

    public function test_invalidation_is_acknowledged_only_after_success(): void
    {
        $event = new DashboardInvalidationData('1', '2', '7', '4');
        $repo = $this->createMock(DashboardInvalidationRepository::class);
        $repo->method('pending')->willReturn($event);
        $cache = $this->createMock(DashboardCache::class);
        $cache->expects(self::once())->method('invalidate')->with('7', '4')->willThrowException(new CacheUnavailable);
        $repo->expects(self::never())->method('acknowledge');
        $this->expectException(CacheUnavailable::class);
        (new InvalidateDashboardUseCase($repo, $cache))->execute(new InvalidateDashboardRequest('1'));
    }

    public function test_acknowledged_delivery_does_not_touch_redis(): void
    {
        $repo = $this->createStub(DashboardInvalidationRepository::class);
        $repo->method('pending')->willReturn(null);
        $cache = $this->createMock(DashboardCache::class);
        $cache->expects(self::never())->method('invalidate');
        self::assertFalse((new InvalidateDashboardUseCase($repo, $cache))->execute(new InvalidateDashboardRequest('1'))->processed);
    }
}
