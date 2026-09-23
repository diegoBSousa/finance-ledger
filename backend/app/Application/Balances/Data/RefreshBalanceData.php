<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

use App\Domain\Shared\DecimalInteger;

final readonly class RefreshBalanceData
{
    public string $ownerUserId;

    public string $accountId;

    public function __construct(string $ownerUserId, string $accountId, public bool $skipLocked = false)
    {
        $this->ownerUserId = (string) DecimalInteger::positive($ownerUserId);
        $this->accountId = (string) DecimalInteger::positive($accountId);
    }
}
