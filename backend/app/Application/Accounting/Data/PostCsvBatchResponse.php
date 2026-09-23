<?php

declare(strict_types=1);

namespace App\Application\Accounting\Data;

final readonly class PostCsvBatchResponse
{
    /** @param list<PostedJournalData> $entries */
    public function __construct(public array $entries) {}
}
