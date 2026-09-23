<?php

declare(strict_types=1);

namespace App\Application\Accounting\Contracts;

use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\Data\PostingBatchResultData;

interface JournalRepository
{
    /**
     * Atomically persist new balanced journals, invalidate projections and record an outbox event.
     * Preserve input order and return the original journal ID for duplicates.
     * Inside an existing transaction, durability depends on the caller's outer commit.
     */
    public function post(PostingBatchData $batch): PostingBatchResultData;
}
