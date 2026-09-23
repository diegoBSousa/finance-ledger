<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Application\Pagination\PageRequest;

final readonly class ListImportsResponse
{
    public function __construct(public ImportPageData $page, public PageRequest $pagination) {}
}
