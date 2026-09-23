<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

final readonly class JournalEntryData
{
    /** @param list<PostingData> $postings */
    public function __construct(
        public string $ownerUserId,
        public string $transactionDate,
        public string $description,
        public array $postings,
    ) {}
}
