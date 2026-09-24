<?php

declare(strict_types=1);

namespace App\Application\Transactions\Data;

final readonly class TransactionData
{
    public function __construct(public string $id, public string $ownerUserId, public string $accountNumber, public string $transactionDate, public string $description, public string $type, public string $amountMinor, public string $currency, public string $createdAt) {}
}
