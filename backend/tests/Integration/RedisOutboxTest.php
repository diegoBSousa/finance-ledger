<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Data\UploadCsvRequest;
use App\Application\Imports\UploadCsvUseCase;
use App\Application\Outbox\Contracts\OutboxPublisher;
use App\Application\Outbox\Contracts\OutboxRepository;
use App\Application\Outbox\RelayOutboxUseCase;
use App\Infrastructure\Messaging\PrepareImportJob;
use App\Infrastructure\Storage\LocalImportFileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\UsesCommittedMysql;
use Tests\Support\TemporaryUploads;

final class RedisOutboxTest extends TestCase
{
    use TemporaryUploads;
    use UsesCommittedMysql { tearDown as private cleanupDatabase; }

    private ?\Redis $redisProbe = null;

    private string $prefix = '';

    protected function tearDown(): void
    {
        if ($this->redisProbe !== null) {
            foreach ($this->redisProbe->keys($this->prefix.'*') as $key) {
                $this->redisProbe->del($key);
            }$this->redisProbe->close();
        }
        $this->removeUploadRoot();
        $this->cleanupDatabase();
    }

    private function prepare(bool $badPublisherPort = false): void
    {
        $this->createUploadRoot();
        if (getenv('REDIS_TEST_ENABLED') !== '1') {
            throw new \RuntimeException('Use the disposable redis-test service with REDIS_TEST_ENABLED=1.');
        }
        $host = getenv('REDIS_TEST_HOST') ?: 'redis-test';
        $port = (int) (getenv('REDIS_TEST_PORT') ?: 6379);
        if (! in_array($host, ['127.0.0.1', 'redis-test'], true)) {
            throw new \RuntimeException('Only the explicitly configured disposable Redis is allowed.');
        }
        $this->prefix = 'finance_ledger_test:'.bin2hex(random_bytes(12)).':';
        config(['database.redis.options.prefix' => $this->prefix]);
        foreach (['default', 'outbox'] as $connection) {
            config([
                'database.redis.'.$connection.'.host' => $host, 'database.redis.'.$connection.'.port' => $connection === 'outbox' && $badPublisherPort ? 1 : $port,
                'database.redis.'.$connection.'.url' => null, 'database.redis.'.$connection.'.database' => 0,
                'database.redis.'.$connection.'.password' => null, 'database.redis.'.$connection.'.username' => null,
            ]);
        }
        config(['queue.connections.redis.block_for' => null]);
        $this->redisProbe = new \Redis;
        $this->redisProbe->connect($host, $port, 2);
        self::assertTrue((bool) $this->redisProbe->ping());
        $this->createUser();
        $this->commitFixtures();
        $this->app->instance(ImportFileStorage::class, new LocalImportFileStorage($this->uploadRoot));
        $this->app->make(UploadCsvUseCase::class)->execute(new UploadCsvRequest('7', $this->sourceFile(), 'a.csv'));
    }

    private function consume(): void
    {
        $job = Queue::connection('redis')->pop('imports');
        self::assertNotNull($job);
        self::assertStringContainsString(PrepareImportJob::class, $job->payload()['displayName']);
        self::assertLessThan(10000, strlen($job->getRawBody()));
        $job->fire();
    }

    public function test_real_redis_publication_is_distinct_from_consumer_acknowledgment(): void
    {
        $this->prepare();
        $result = $this->app->make(RelayOutboxUseCase::class)->execute();
        self::assertSame(1, $result->published);
        self::assertSame('published', DB::table('outbox_deliveries')->where('consumer', 'import-preparer')->value('status'));
        self::assertNull(DB::table('imports')->value('prepared_at'));
        $this->consume();
        self::assertSame('acknowledged', DB::table('outbox_deliveries')->where('consumer', 'import-preparer')->value('status'));
        self::assertNotNull(DB::table('imports')->value('prepared_at'));
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertSame(0, $this->app->make(RelayOutboxUseCase::class)->execute()->claimed);
    }

    public function test_duplicate_redis_jobs_do_not_duplicate_first_chunk_intent(): void
    {
        $this->prepare();
        $repo = $this->app->make(OutboxRepository::class);
        $delivery = $repo->claim()->deliveries[0];
        $publisher = $this->app->make(OutboxPublisher::class);
        $publisher->publish($delivery);
        $publisher->publish($delivery);
        $this->consume();
        $this->consume();
        $repo->published($delivery);
        self::assertSame(1, DB::table('outbox_events')->where('event_type', 'ImportChunkRequested')->count());
        self::assertSame('acknowledged', DB::table('outbox_deliveries')->where('id', $delivery->id)->value('status'));
    }

    public function test_lost_redis_message_is_republished_from_mysql_until_acknowledged(): void
    {
        $this->prepare();
        $relay = $this->app->make(RelayOutboxUseCase::class);
        $relay->execute();
        $lost = Queue::connection('redis')->pop('imports');
        self::assertNotNull($lost);
        $lost->delete();
        DB::table('outbox_deliveries')->update(['available_at' => now()->subMinute()]);
        self::assertSame(1, $relay->execute()->published);
        $this->consume();
        self::assertNotNull(DB::table('imports')->value('prepared_at'));
    }

    public function test_connection_failure_keeps_import_and_intent_durable_for_retry(): void
    {
        $this->prepare(true);
        $response = $this->app->make(RelayOutboxUseCase::class)->execute();
        self::assertSame(1, $response->retrying);
        self::assertSame('pending', DB::table('outbox_deliveries')->value('status'));
        self::assertSame('pending', DB::table('imports')->value('status'));
        self::assertSame(1, DB::table('outbox_deliveries')->value('attempts'));
    }
}
