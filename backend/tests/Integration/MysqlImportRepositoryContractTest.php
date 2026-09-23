<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Imports\Contracts\ImportRepository;
use Tests\Core\Contracts\ImportRepositoryContract;
use Tests\Integration\Support\UsesCommittedMysql;

final class MysqlImportRepositoryContractTest extends ImportRepositoryContract
{
    use UsesCommittedMysql;

    protected function repository(): ImportRepository
    {
        $this->createUser('7');
        $this->createUser('8');
        $this->commitFixtures();

        return $this->app->make(ImportRepository::class);
    }
}
