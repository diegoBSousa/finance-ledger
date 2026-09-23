<?php

declare(strict_types=1);

namespace App\Application\Accounting\Data;

final readonly class PostedJournalData
{
    public function __construct(public string $sourceRowHash, public string $journalEntryId, public string $status) {}
}
