<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Application\Shared\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
