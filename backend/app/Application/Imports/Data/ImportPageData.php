<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ImportPageData
{
    /** @param list<ImportData> $imports */
    public function __construct(public array $imports, public int $total) {}
}
