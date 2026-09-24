<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Application\Pagination\PageRequest;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;

final readonly class ImportRowsQueryData
{
    public function __construct(public string $actorUserId, public string $importId, public PageRequest $pagination, public ?string $status = null)
    {
        DecimalInteger::positive($actorUserId);
        DecimalInteger::positive($importId);
        if ($status !== null && ! in_array($status, ['inserted', 'duplicate', 'rejected'], true)) {
            throw new DomainViolation('invalid_import_status', 'Unsupported row status.');
        }
    }
}
