<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

final readonly class GetAccountBalanceResponse
{
    public function __construct(public AccountBalanceData $balance) {}
}
