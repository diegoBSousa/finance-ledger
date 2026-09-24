<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Outbox\Contracts\OutboxRepository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Core\Contracts\ImportRepositoryContract;
use Tests\Integration\Support\UsesCommittedMysql;

final class OutboxPersistenceTest extends TestCase
{
    use UsesCommittedMysql;

    private function prepare(int $count = 1): OutboxRepository
    {
        $this->createUser();
        $this->commitFixtures();
        for ($i = 0; $i < $count; $i++) {
            $this->app->make(ImportRepository::class)->register(ImportRepositoryContract::registration());
        }

        return $this->app->make(OutboxRepository::class);
    }

    public function test_claim_is_bounded_and_active_leases_are_not_claimed_twice(): void
    {
        $repo = $this->prepare(12);
        $first = $repo->claim();
        $second = $repo->claim();
        self::assertCount(10, $first->deliveries);
        self::assertCount(2, $second->deliveries);
        self::assertCount(0, $repo->claim()->deliveries);
        self::assertSame([], array_intersect(array_column($first->deliveries, 'id'), array_column($second->deliveries, 'id')));
    }

    public function test_expired_publication_lease_is_recovered_with_new_token_and_old_receipt_cannot_overwrite_it(): void
    {
        $repo = $this->prepare();
        $first = $repo->claim()->deliveries[0];
        DB::table('outbox_deliveries')->where('id', $first->id)->update(['lease_expires_at' => now()->subMinute()]);
        $second = $repo->claim()->deliveries[0];
        self::assertNotSame($first->leaseToken, $second->leaseToken);
        self::assertSame(2, $second->attempts);
        $repo->published($first);
        $repo->retry($first);
        self::assertSame($second->leaseToken, DB::table('outbox_deliveries')->value('lease_token'));
        $repo->published($second);
        self::assertSame('published', DB::table('outbox_deliveries')->value('status'));
    }

    public function test_acknowledgment_before_publication_receipt_is_never_overwritten(): void
    {
        $repo = $this->prepare();
        $delivery = $repo->claim()->deliveries[0];
        DB::table('outbox_deliveries')->where('id', $delivery->id)->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'lease_token' => null, 'lease_expires_at' => null]);
        $repo->published($delivery);
        $repo->retry($delivery);
        self::assertSame('acknowledged', DB::table('outbox_deliveries')->value('status'));
        self::assertCount(0, $repo->claim()->deliveries);
    }

    public function test_unacknowledged_publication_is_replayed_after_deadline_and_retry_obeys_backoff(): void
    {
        $repo = $this->prepare();
        $delivery = $repo->claim()->deliveries[0];
        $repo->published($delivery);
        self::assertCount(0, $repo->claim()->deliveries);
        DB::table('outbox_deliveries')->where('id', $delivery->id)->update(['available_at' => now()->subMinute()]);
        $again = $repo->claim()->deliveries[0];
        self::assertSame($delivery->id, $again->id);
        $repo->retry($again);
        self::assertSame('pending', DB::table('outbox_deliveries')->value('status'));
        self::assertCount(0, $repo->claim()->deliveries);
        self::assertSame('publication_unavailable', DB::table('outbox_deliveries')->value('last_error'));
    }

    public function test_unimplemented_consumers_remain_durable_and_are_not_published(): void
    {
        $repo = $this->prepare();
        DB::table('outbox_deliveries')->update(['consumer' => 'future-consumer']);
        self::assertCount(0, $repo->claim()->deliveries);
        self::assertSame('pending', DB::table('outbox_deliveries')->value('status'));
    }
}
