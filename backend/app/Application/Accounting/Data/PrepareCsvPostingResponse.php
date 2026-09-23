<?php

declare(strict_types=1);

namespace App\Application\Accounting\Data;

use App\Domain\Accounting\Data\JournalEntryData;

final readonly class PrepareCsvPostingResponse
{
    public function __construct(
        public string $uploadedByUserId,
        public string $financialAccountId,
        public string $financialAccountNumber,
        public string $movementType,
        public string $sourceRowHash,
        public string $canonicalRecord,
        public string $originalDescription,
        public JournalEntryData $journal,
    ) {}
}
