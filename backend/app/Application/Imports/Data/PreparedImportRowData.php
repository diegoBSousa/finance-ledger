<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Application\Accounting\Data\PostJournalData;
use App\Domain\Shared\DomainViolation;

final readonly class PreparedImportRowData
{
    public function __construct(public string $recordNumber, public ?PostJournalData $posting, public ?string $errorCode = null)
    {
        if (($posting === null) === ($errorCode === null)) {
            throw new DomainViolation('invalid_import_result', 'An import row requires a posting or a rejection.');
        }
    }
}
