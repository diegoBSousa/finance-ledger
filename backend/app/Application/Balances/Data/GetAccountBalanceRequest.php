<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

final readonly class GetAccountBalanceRequest
{
    public function __construct(public string $actorUserId, public string $accountNumber) {}
}
