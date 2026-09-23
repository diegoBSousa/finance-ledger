<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Outbox\Contracts\OutboxPublisher;
use App\Application\Outbox\Contracts\OutboxRepository;
use App\Application\Outbox\Data\ClaimedDeliveriesData;
use App\Application\Outbox\Data\OutboxDeliveryData;
use App\Application\Outbox\OutboxUnavailable;
use App\Application\Outbox\PublicationUnavailable;
use App\Application\Outbox\RelayOutboxUseCase;
use PHPUnit\Framework\TestCase;

final class RelayOutboxUseCaseTest extends TestCase
{
    private function delivery(string $id): OutboxDeliveryData
    {
        return new OutboxDeliveryData($id, $id, 'import-preparer', 'ImportRequested', 1, '{}', 1, 'lease');
    }

    public function test_failed_publication_is_retried_and_does_not_block_other_deliveries(): void
    {
        $first = $this->delivery('1');
        $second = $this->delivery('2');
        $outbox = $this->createMock(OutboxRepository::class);
        $outbox->method('claim')->willReturn(new ClaimedDeliveriesData([$first, $second]));
        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(function ($delivery): void {
            if ($delivery->id === '1') {
                throw new PublicationUnavailable;
            }
        });
        $outbox->expects(self::once())->method('retry')->with($first);
        $outbox->expects(self::once())->method('published')->with($second);
        $response = (new RelayOutboxUseCase($outbox, $publisher))->execute();
        self::assertSame(2, $response->claimed);
        self::assertSame(1, $response->published);
        self::assertSame(1, $response->retrying);
    }

    public function test_lost_publication_receipt_is_left_for_lease_recovery_without_claiming_failure_to_publish(): void
    {
        $outbox = $this->createMock(OutboxRepository::class);
        $outbox->method('claim')->willReturn(new ClaimedDeliveriesData([$this->delivery('1')]));
        $outbox->method('published')->willThrowException(new OutboxUnavailable);
        $outbox->expects(self::never())->method('retry');
        $publisher = $this->createMock(OutboxPublisher::class);
        $publisher->expects(self::once())->method('publish');
        $this->expectException(OutboxUnavailable::class);
        (new RelayOutboxUseCase($outbox, $publisher))->execute();
    }
}
