<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

final readonly class PostingData
{
    public function __construct(
        public string $accountId,
        public string $side,
        public string $amountMinor,
        public string $currency,
    ) {}
}
