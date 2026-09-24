<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Application\Pagination\PageRequest;

final readonly class ListImportRowsResponse
{
    public function __construct(public ImportRowsPageData $page, public PageRequest $pagination) {}
}
