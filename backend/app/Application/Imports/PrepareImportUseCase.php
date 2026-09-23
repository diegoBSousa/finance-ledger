<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Contracts\ImportPreparationRepository;
use App\Application\Imports\Data\PrepareImportRequest;
use App\Application\Imports\Data\PrepareImportResponse;

final readonly class PrepareImportUseCase
{
    public function __construct(private ImportPreparationRepository $imports, private ImportFileStorage $files) {}

    public function execute(PrepareImportRequest $request): PrepareImportResponse
    {
        $source = $this->imports->claim($request->deliveryId);
        if ($source === null) {
            return new PrepareImportResponse(false);
        }
        try {
            $validated = $this->files->validate($source->file);
            $this->imports->complete($request->deliveryId, $source, $validated->headerOffset);

            return new PrepareImportResponse(true);
        } catch (InvalidImportFile $error) {
            $this->imports->reject($request->deliveryId, $source, $error->reason);

            return new PrepareImportResponse(false);
        } catch (ImportUnavailable $error) {
            $this->imports->release($source);
            throw $error;
        }
    }
}
