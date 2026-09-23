<?php

declare(strict_types=1);

namespace App\Application\Imports\Contracts;

use App\Application\Imports\Data\ImportData;
use App\Application\Imports\Data\ImportPageData;
use App\Application\Imports\Data\ImportPageQueryData;
use App\Application\Imports\Data\ImportRegistrationData;

interface ImportRepository
{
    /** Own transaction: the import and initial event/delivery commit together. */
    public function register(ImportRegistrationData $request): ImportData;

    public function find(string $actorUserId, string $importId): ?ImportData;

    public function page(ImportPageQueryData $query): ImportPageData;

    /** Conservative cleanup: on uncertain persistence errors retain the file. */
    public function referencesFile(string $path): bool;
}
