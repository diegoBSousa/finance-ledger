<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use Tests\Doubles\InMemoryTokenRevocationRepository;

final class InMemoryTokenRevocationRepositoryContractTest extends TokenRevocationRepositoryContract
{
    protected function repository(): TokenRevocationRepository
    {
        return new InMemoryTokenRevocationRepository;
    }
}
