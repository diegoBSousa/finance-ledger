<?php

declare(strict_types=1);

namespace App\Application\Outbox\Data;

final readonly class RelayOutboxResponse
{
    public function __construct(public int $claimed, public int $published, public int $retrying) {}
}
