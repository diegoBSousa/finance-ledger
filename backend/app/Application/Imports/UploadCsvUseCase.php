<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\ImportRegistrationData;
use App\Application\Imports\Data\UploadCsvRequest;
use App\Application\Imports\Data\UploadCsvResponse;
use App\Domain\Shared\DecimalInteger;

final readonly class UploadCsvUseCase
{
    public function __construct(private ImportFileStorage $files, private ImportRepository $imports) {}

    public function execute(UploadCsvRequest $request): UploadCsvResponse
    {
        $actor = (string) DecimalInteger::positive($request->actorUserId);
        $name = basename(str_replace('\\', '/', $request->originalName));
        if (strlen($name) > 255 || preg_match('//u', $name) !== 1 || str_contains($name, "\0") || preg_match('/\.csv\z/i', $name) !== 1) {
            throw new InvalidImportFile;
        }
        $file = $this->files->store($request->temporaryPath);
        try {
            return new UploadCsvResponse($this->imports->register(new ImportRegistrationData($actor, $file, $name)));
        } catch (ImportUnavailable $error) {
            try {
                // A lost commit reply may still mean the row exists. Never blindly delete its source.
                if (! $this->imports->referencesFile($file->path)) {
                    $this->files->delete($file->path);
                }
            } catch (ImportUnavailable) { /* Leave an orphan for later reconciliation when uncertain. */
            }
            throw $error;
        }
    }
}
