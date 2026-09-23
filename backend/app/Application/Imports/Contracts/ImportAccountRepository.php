<?php

declare(strict_types=1);

namespace App\Application\Imports\Contracts;

use App\Application\Imports\Data\ImportAccountsData;

interface ImportAccountRepository
{
    /** @param list<string> $externalNumbers Financial numbers in one bounded chunk. */
    public function resolve(string $actorUserId, array $externalNumbers): ImportAccountsData;
}
