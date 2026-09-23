<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

final readonly class RefreshedBalanceData
{
    public function __construct(public AccountBalanceData $balance, public bool $recalculated) {}
}
