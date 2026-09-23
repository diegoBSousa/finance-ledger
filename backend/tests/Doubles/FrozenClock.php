<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Shared\Contracts\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public int $timestamp = 1790000000) {}

    public function now(): int
    {
        return $this->timestamp;
    }
}
