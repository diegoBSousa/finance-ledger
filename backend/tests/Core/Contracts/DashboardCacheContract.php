<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Data\DashboardData;
use PHPUnit\Framework\TestCase;

abstract class DashboardCacheContract extends TestCase
{
    abstract protected function cache(): DashboardCache;

    protected function data(string $owner = '7', string $revision = '2'): DashboardData
    {
        return new DashboardData($owner, $revision, '0', '9007199254740993', '-9007199254740993', '1');
    }

    public function test_misses_are_null_and_cache_is_scoped_by_owner_and_exact_revision(): void
    {
        $cache = $this->cache();
        self::assertNull($cache->get('7', '2'));
        $cache->put($this->data());
        self::assertEquals($this->data(), $cache->get('7', '2'));
        self::assertNull($cache->get('8', '2'));
        self::assertNull($cache->get('7', '4'));
    }

    public function test_zero_revision_can_be_replaced_and_old_values_are_removed(): void
    {
        $cache = $this->cache();
        $cache->put($this->data(revision: '0'));
        $cache->put($this->data(revision: '2'));
        self::assertNull($cache->get('7', '0'));
        self::assertEquals($this->data(), $cache->get('7', '2'));
    }

    public function test_late_writer_does_not_overwrite_a_newer_cached_revision(): void
    {
        $cache = $this->cache();
        $cache->put($this->data(revision: '4'));
        $cache->put($this->data(revision: '2'));
        self::assertNull($cache->get('7', '2'));
        self::assertEquals($this->data(revision: '4'), $cache->get('7', '4'));
    }

    public function test_repeated_and_out_of_order_events_preserve_current_revision_and_other_owners(): void
    {
        $cache = $this->cache();
        $cache->put($this->data());
        $cache->put($this->data('8'));
        $cache->invalidate('7', '2');
        $cache->invalidate('7', '0');
        self::assertNotNull($cache->get('7', '2'));
        $cache->invalidate('7', '4');
        $cache->invalidate('7', '4');
        self::assertNull($cache->get('7', '2'));
        self::assertNotNull($cache->get('8', '2'));
    }

    public function test_revision_comparison_preserves_unsigned_64_bit_precision(): void
    {
        $cache = $this->cache();
        $cache->put($this->data(revision: '9007199254740993'));
        $cache->invalidate('7', '9007199254740992');
        self::assertNotNull($cache->get('7', '9007199254740993'));
        $cache->put($this->data(revision: '18446744073709551615'));
        self::assertNull($cache->get('7', '9007199254740993'));
        self::assertNotNull($cache->get('7', '18446744073709551615'));
    }
}
