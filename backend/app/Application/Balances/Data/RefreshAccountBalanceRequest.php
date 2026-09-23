<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

final readonly class RefreshAccountBalanceRequest
{
    public function __construct(public string $ownerUserId, public string $accountId, public bool $skipLocked = false) {}
}
