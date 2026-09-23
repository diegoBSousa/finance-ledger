<?php

declare(strict_types=1);

namespace App\Application\Balances;

use RuntimeException;

final class BalanceUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Account balances are temporarily unavailable.');
    }
}
