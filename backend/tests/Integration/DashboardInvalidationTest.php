<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Dashboard\CacheUnavailable;
use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Data\DashboardData;
use App\Application\Dashboard\Data\InvalidateDashboardRequest;
use App\Application\Dashboard\InvalidateDashboardUseCase;
use App\Application\Outbox\OutboxUnavailable;
use App\Application\Outbox\RelayOutboxUseCase;
use App\Infrastructure\Messaging\InvalidateDashboardJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\UsesCsvImports;
use Tests\Integration\Support\UsesDashboardRedis;

final class DashboardInvalidationTest extends TestCase
{
    use UsesCsvImports { setUp as private importSetup;
        tearDown as private importCleanup; }
    use UsesDashboardRedis;

    protected function setUp(): void
    {
        $this->importSetup();
        $this->prepareRedis();
    }

    protected function tearDown(): void
    {
        $this->cleanupRedis();
        $this->importCleanup();
    }

    private function prepare(): string
    {
        $cache = $this->app->make(DashboardCache::class);
        $cache->put(new DashboardData('7', '0', '0', '0', '0', '0'));
        $cache->put(new DashboardData('8', '0', '0', '0', '0', '0'));
        $id = $this->uploadCsv($this->csv(1));
        $this->consumeImport($id);

        return (string) DB::table('outbox_deliveries')->where('consumer', 'dashboard-cache-invalidator')->value('id');
    }

    public function test_relay_publishes_cache_job_and_recovers_lost_message_without_global_deletion(): void
    {
        $id = $this->prepare();
        $relay = $this->app->make(RelayOutboxUseCase::class);
        self::assertSame(1, $relay->execute()->published);
        $lost = Queue::connection('redis')->pop('default');
        self::assertNotNull($lost);
        self::assertStringContainsString(InvalidateDashboardJob::class, $lost->payload()['displayName']);
        self::assertLessThan(10000, strlen($lost->getRawBody()));
        $lost->delete();
        DB::table('outbox_deliveries')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        self::assertSame(1, $relay->execute()->published);
        $job = Queue::connection('redis')->pop('default');
        self::assertNotNull($job);
        $job->fire();
        $job->delete();
        self::assertSame('acknowledged', DB::table('outbox_deliveries')->find($id)->status);
        $cache = $this->app->make(DashboardCache::class);
        self::assertNull($cache->get('7', '0'));
        self::assertNotNull($cache->get('8', '0'));
        self::assertFalse($this->app->make(InvalidateDashboardUseCase::class)->execute(new InvalidateDashboardRequest($id))->processed);
        self::assertSame(0, $relay->execute()->claimed);
    }

    public function test_redis_failure_leaves_delivery_recoverable_until_invalidation_succeeds(): void
    {
        $id = $this->prepare();
        $port = config('database.redis.dashboard.port');
        config(['database.redis.dashboard.port' => 1]);
        $this->reloadRedisConfiguration();
        $useCase = $this->app->make(InvalidateDashboardUseCase::class);
        try {
            $useCase->execute(new InvalidateDashboardRequest($id));
            self::fail('Cache failure must not acknowledge.');
        } catch (CacheUnavailable) {
            self::assertSame('pending', DB::table('outbox_deliveries')->find($id)->status);
        }
        config(['database.redis.dashboard.port' => $port]);
        $this->reloadRedisConfiguration();
        self::assertTrue($useCase->execute(new InvalidateDashboardRequest($id))->processed);
        self::assertSame('acknowledged', DB::table('outbox_deliveries')->find($id)->status);
    }

    public function test_sql_ack_failure_allows_safe_repeat_after_successful_redis_delete(): void
    {
        $id = $this->prepare();
        DB::unprepared("CREATE TRIGGER dashboard_ack_fail BEFORE UPDATE ON outbox_deliveries FOR EACH ROW BEGIN IF NEW.status = 'acknowledged' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected acknowledgment failure'; END IF; END");
        $useCase = $this->app->make(InvalidateDashboardUseCase::class);
        try {
            $useCase->execute(new InvalidateDashboardRequest($id));
            self::fail('SQL acknowledgment must fail.');
        } catch (OutboxUnavailable) {
            self::assertSame('pending', DB::table('outbox_deliveries')->find($id)->status);
        }
        self::assertNull($this->app->make(DashboardCache::class)->get('7', '0'));
        DB::unprepared('DROP TRIGGER dashboard_ack_fail');
        self::assertTrue($useCase->execute(new InvalidateDashboardRequest($id))->processed);
    }

    #[DataProvider('unsupportedEvents')]
    public function test_unsupported_events_are_failed_without_touching_cache(string $type, int $version, array $payload): void
    {
        $this->app->make(DashboardCache::class)->put(new DashboardData('7', '0', '0', '0', '0', '0'));
        $event = DB::table('outbox_events')->insertGetId(['event_type' => $type, 'event_version' => $version, 'payload' => json_encode($payload)]);
        $id = (string) DB::table('outbox_deliveries')->insertGetId(['event_id' => $event, 'consumer' => 'dashboard-cache-invalidator']);
        self::assertFalse($this->app->make(InvalidateDashboardUseCase::class)->execute(new InvalidateDashboardRequest($id))->processed);
        self::assertSame('failed', DB::table('outbox_deliveries')->find($id)->status);
        self::assertNotNull($this->app->make(DashboardCache::class)->get('7', '0'));
    }

    public static function unsupportedEvents(): iterable
    {
        $payload = ['owner_user_id' => '7', 'financial_revision' => '2', 'currency' => 'BRL'];
        yield ['WrongType', 1, $payload];
        yield ['LedgerChanged', 2, $payload];
        yield ['LedgerChanged', 1, array_replace($payload, ['owner_user_id' => 7])];
        yield ['LedgerChanged', 1, array_replace($payload, ['financial_revision' => '2e1'])];
        yield ['LedgerChanged', 1, array_replace($payload, ['currency' => 'USD'])];
    }
}
