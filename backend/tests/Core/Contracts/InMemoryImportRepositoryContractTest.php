<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Imports\Contracts\ImportRepository;
use Tests\Doubles\InMemoryImportRepository;

final class InMemoryImportRepositoryContractTest extends ImportRepositoryContract
{
    protected function repository(): ImportRepository
    {
        return new InMemoryImportRepository;
    }
}
