<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class CanonicalRowData
{
    public function __construct(
        public string $date,
        public string $description,
        public string $amountMinor,
        public string $movementType,
        public string $accountNumber,
        public string $canonicalRecord,
        public string $sourceRowHash,
    ) {}
}
