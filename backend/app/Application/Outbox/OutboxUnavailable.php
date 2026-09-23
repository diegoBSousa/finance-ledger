<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use RuntimeException;

final class OutboxUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Outbox delivery is temporarily unavailable.');
    }
}
