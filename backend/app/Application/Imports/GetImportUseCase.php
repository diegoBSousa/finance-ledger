<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\GetImportRequest;
use App\Application\Imports\Data\GetImportResponse;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;

final readonly class GetImportUseCase
{
    public function __construct(private ImportRepository $imports) {}

    public function execute(GetImportRequest $request): GetImportResponse
    {
        try {
            $id = (string) DecimalInteger::positive($request->importId);
        } catch (DomainViolation) {
            throw new ImportNotFound;
        }
        $import = $this->imports->find((string) DecimalInteger::positive($request->actorUserId), $id);
        if ($import === null) {
            throw new ImportNotFound;
        }

        return new GetImportResponse($import);
    }
}
