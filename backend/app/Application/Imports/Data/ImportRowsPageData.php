<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ImportRowsPageData
{
    /** @param list<ImportRowData> $rows */
    public function __construct(public array $rows, public int $total) {}
}
