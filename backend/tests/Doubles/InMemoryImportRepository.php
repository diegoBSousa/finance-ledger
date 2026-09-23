<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\ImportData;
use App\Application\Imports\Data\ImportPageData;
use App\Application\Imports\Data\ImportPageQueryData;
use App\Application\Imports\Data\ImportRegistrationData;

final class InMemoryImportRepository implements ImportRepository
{
    /** @var array<string,ImportData> */
    public array $imports = [];

    /** @var array<string,string> */
    public array $paths = [];

    public function register(ImportRegistrationData $request): ImportData
    {
        $id = (string) (count($this->imports) + 1);
        $row = new ImportData($id, $request->actorUserId, $request->originalName, $request->file->sizeBytes, 'pending', '0', '0', '0', '0', '2026-09-23T00:00:00.000000Z', null, null, null, null);
        $this->imports[$id] = $row;
        $this->paths[$id] = $request->file->path;

        return $row;
    }

    public function find(string $actorUserId, string $importId): ?ImportData
    {
        $row = $this->imports[$importId] ?? null;

        return $row?->uploadedByUserId === $actorUserId ? $row : null;
    }

    public function page(ImportPageQueryData $query): ImportPageData
    {
        $rows = array_values(array_filter(array_reverse($this->imports), fn (ImportData $row) => $row->uploadedByUserId === $query->actorUserId));

        return new ImportPageData(array_slice($rows, $query->pagination->offset(), $query->pagination->perPage), count($rows));
    }

    public function referencesFile(string $path): bool
    {
        return in_array($path, $this->paths, true);
    }
}
