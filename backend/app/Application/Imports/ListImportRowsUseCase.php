<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Imports\Contracts\ImportRowsRepository;
use App\Application\Imports\Data\ListImportRowsRequest;
use App\Application\Imports\Data\ListImportRowsResponse;
use App\Application\Shared\ReadUnavailable;

final readonly class ListImportRowsUseCase
{
    public function __construct(private ImportRowsRepository $repository) {}

    public function execute(ListImportRowsRequest $request): ListImportRowsResponse
    {
        $query = $request->query;
        $page = $this->repository->page($query) ?? throw new ImportNotFound;
        if (count($page->rows) > $query->pagination->perPage) {
            throw new ReadUnavailable;
        }
        foreach ($page->rows as $row) {
            if ($row->importId !== $query->importId || ($query->status !== null && $row->status !== $query->status)) {
                throw new ReadUnavailable;
            }
        }

        return new ListImportRowsResponse($page, $query->pagination);
    }
}
