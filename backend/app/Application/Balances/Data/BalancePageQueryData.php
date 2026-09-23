<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

use App\Application\Pagination\PageRequest;
use App\Domain\Shared\DecimalInteger;

final readonly class BalancePageQueryData
{
    public string $ownerUserId;

    public ?string $accountNumber;

    public function __construct(string $ownerUserId, public PageRequest $pagination, ?string $accountNumber = null)
    {
        $this->ownerUserId = (string) DecimalInteger::positive($ownerUserId);
        $this->accountNumber = $accountNumber === null ? null : (string) DecimalInteger::positive($accountNumber);
    }
}
