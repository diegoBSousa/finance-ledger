<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

use App\Domain\Accounting\Data\AccountData;

final readonly class AccountBalanceData
{
    public function __construct(
        public AccountData $account,
        public string $debitTotalMinor,
        public string $creditTotalMinor,
        public string $balanceMinor,
        public string $ledgerVersion,
        public string $calculatedVersion,
        public ?string $calculatedAt,
        public bool $staled,
    ) {}
}
