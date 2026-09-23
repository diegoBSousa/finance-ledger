<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\ImportPageQueryData;
use App\Application\Imports\Data\ListImportsRequest;
use App\Application\Imports\Data\ListImportsResponse;
use App\Domain\Shared\DecimalInteger;

final readonly class ListImportsUseCase
{
    public function __construct(private ImportRepository $imports) {}

    public function execute(ListImportsRequest $request): ListImportsResponse
    {
        $query = new ImportPageQueryData((string) DecimalInteger::positive($request->actorUserId), $request->pagination);

        return new ListImportsResponse($this->imports->page($query), $request->pagination);
    }
}
