<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Shared\ReadUnavailable;
use App\Domain\Shared\DomainViolation;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDOException;

final class ConsistentRead
{
    public const string CONNECTION = 'ledger_read';

    /** @template T
     * @param  Closure(Connection): T  $read
     * @return T
     */
    public function run(Closure $read): mixed
    {
        try {
            $connection = DB::connection(self::CONNECTION);
            if ($connection->transactionLevel() !== 0) {
                throw new ReadUnavailable;
            }

            return $connection->transaction(fn () => $read($connection));
        } catch (PDOException|DomainViolation) {
            throw new ReadUnavailable;
        }
    }
}
