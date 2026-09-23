<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Application\Shared\Contracts\Clock;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class JwtClock implements ClockInterface
{
    public function __construct(private Clock $clock) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$this->clock->now());
    }
}
