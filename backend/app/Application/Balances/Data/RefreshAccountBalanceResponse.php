<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

final readonly class RefreshAccountBalanceResponse
{
    public function __construct(public ?AccountBalanceData $balance, public bool $recalculated) {}
}
