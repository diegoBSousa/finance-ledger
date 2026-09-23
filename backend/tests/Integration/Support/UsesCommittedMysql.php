<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Support\Facades\DB;

/** Projection tests use independent sessions and therefore require committed fixtures. */
trait UsesCommittedMysql
{
    use UsesMysql {
        tearDown as private rollbackAndDisconnect;
    }

    private bool $hasCommittedFixtures = false;

    protected function commitFixtures(): void
    {
        DB::commit();
        $this->hasCommittedFixtures = true;
    }

    protected function tearDown(): void
    {
        foreach (DB::getConnections() as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->disconnect();
        }
        $this->rollbackAndDisconnect();
        if ($this->hasCommittedFixtures) {
            MysqlDatabase::migrate($this);
        }
    }
}
