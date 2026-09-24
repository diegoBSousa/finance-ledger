<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ListImportRowsRequest
{
    public function __construct(public ImportRowsQueryData $query) {}
}
