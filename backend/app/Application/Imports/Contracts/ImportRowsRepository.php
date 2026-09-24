<?php

declare(strict_types=1);

namespace App\Application\Imports\Contracts;

use App\Application\Imports\Data\ImportRowsPageData;
use App\Application\Imports\Data\ImportRowsQueryData;

interface ImportRowsRepository
{
    /** Logical record order; null for missing/foreign import, including when it has zero results. */
    public function page(ImportRowsQueryData $query): ?ImportRowsPageData;
}
