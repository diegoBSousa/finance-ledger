<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use App\Application\Outbox\Contracts\OutboxPublisher;
use App\Application\Outbox\Contracts\OutboxRepository;
use App\Application\Outbox\Data\RelayOutboxResponse;

final readonly class RelayOutboxUseCase
{
    public function __construct(private OutboxRepository $outbox, private OutboxPublisher $publisher) {}

    public function execute(): RelayOutboxResponse
    {
        $claims = $this->outbox->claim();
        $published = $retrying = 0;
        foreach ($claims->deliveries as $delivery) {
            try {
                $this->publisher->publish($delivery);
            } catch (PublicationUnavailable) {
                $this->outbox->retry($delivery);
                $retrying++;

                continue;
            }
            $this->outbox->published($delivery);
            $published++;
        }

        return new RelayOutboxResponse(count($claims->deliveries), $published, $retrying);
    }
}
