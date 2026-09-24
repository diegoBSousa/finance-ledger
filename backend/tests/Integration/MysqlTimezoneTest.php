<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Persistence\Models\ImportRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\UsesCommittedMysql;

final class MysqlTimezoneTest extends TestCase
{
    use UsesCommittedMysql;

    public function test_import_timestamps_preserve_the_instant_when_mysql_sessions_start_outside_utc(): void
    {
        $this->createUser();
        $id = DB::table('imports')->insertGetId($this->importData() + ['original_name' => 'timezone.csv']);
        $epoch = (int) DB::table('imports')->where('id', $id)->selectRaw('UNIX_TIMESTAMP(created_at) AS epoch')->value('epoch');
        $this->commitFixtures();

        foreach (['mysql', 'balance_projection', 'ledger_read'] as $name) {
            $configuration = config('database.connections.'.$name);
            // Simulate a non-UTC server default before Laravel configures the new PDO session.
            // No GLOBAL setting or administrative privileges are needed.
            $configuration['options'][PDO::MYSQL_ATTR_INIT_COMMAND] = "SET SESSION time_zone = '-03:00'";
            config(['database.connections.timezone_probe' => $configuration]);
            DB::purge('timezone_probe');
            $record = ImportRecord::on('timezone_probe')->findOrFail($id)->toData();
            self::assertSame($epoch, (new DateTimeImmutable($record->createdAt))->getTimestamp(), $name);
            self::assertSame('+00:00', DB::connection('timezone_probe')->selectOne('SELECT @@SESSION.time_zone AS zone')->zone, $name);
        }
    }
}
