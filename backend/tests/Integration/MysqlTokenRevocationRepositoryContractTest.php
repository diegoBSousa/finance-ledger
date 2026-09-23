<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use Tests\Core\Contracts\TokenRevocationRepositoryContract;
use Tests\Integration\Support\UsesMysql;

final class MysqlTokenRevocationRepositoryContractTest extends TokenRevocationRepositoryContract
{
    use UsesMysql;

    protected function repository(): TokenRevocationRepository
    {
        $this->createUser();

        return $this->app->make(TokenRevocationRepository::class);
    }
}
