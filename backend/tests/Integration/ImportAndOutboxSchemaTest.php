<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Integration\Support\UsesMysql;

final class ImportAndOutboxSchemaTest extends TestCase
{
    use UsesMysql;

    #[DataProvider('invalidImportAttributes')]
    public function test_mysql_protects_import_size_counters_checkpoint_and_lease(array $changes): void
    {
        $this->createUser();
        $this->expectException(QueryException::class);
        DB::table('imports')->insert(array_replace($this->importData(), $changes));
    }

    public static function invalidImportAttributes(): iterable
    {
        yield 'over 100 decimal MB' => [['file_size_bytes' => 100000001]];
        yield 'empty file' => [['file_size_bytes' => 0]];
        yield 'offset after end of file' => [['byte_offset' => 100000001]];
        yield 'counters do not reconcile' => [['processed_rows' => 1]];
        yield 'unknown status' => [['status' => 'unknown']];
        yield 'lease missing expiry' => [['lease_token' => '5c8536c2-a335-46dd-8999-f1282cfbb5bc']];
        yield 'malformed checksum' => [['file_checksum' => 'not-a-checksum']];
    }

    public function test_full_size_file_and_consistent_checkpoint_are_accepted(): void
    {
        $this->createUser();
        $id = DB::table('imports')->insertGetId($this->importData());
        DB::table('imports')->where('id', $id)->update([
            'processed_rows' => 3, 'inserted_rows' => 1, 'duplicate_rows' => 1, 'rejected_rows' => 1,
            'byte_offset' => 100000000, 'last_record_number' => 4, 'checkpoint_version' => 1,
        ]);
        self::assertSame(100000000, DB::table('imports')->where('id', $id)->value('byte_offset'));
    }

    public function test_each_import_record_has_one_result_but_hashes_can_repeat(): void
    {
        $this->persistAccounts(Accounts::data());
        $import = DB::table('imports')->insertGetId($this->importData());
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        $result = [
            'import_id' => $import, 'source_record_number' => 2,
            'source_row_hash' => hash('sha256', 'fixture'),
            'status' => 'inserted', 'journal_entry_id' => $journal,
        ];
        DB::table('import_rows')->insert($result);
        DB::table('import_rows')->insert(array_replace($result, ['source_record_number' => 3, 'status' => 'duplicate']));
        self::assertSame(2, DB::table('import_rows')->count());

        $this->expectException(QueryException::class);
        DB::table('import_rows')->insert($result);
    }

    public function test_rejected_row_does_not_reserve_the_financial_hash(): void
    {
        $this->persistAccounts(Accounts::data());
        $import = DB::table('imports')->insertGetId($this->importData());
        DB::table('import_rows')->insert([
            'import_id' => $import, 'source_record_number' => 2,
            'source_row_hash' => hash('sha256', 'fixture'), 'status' => 'rejected', 'error_code' => 'inactive_account',
        ]);
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        DB::table('import_rows')->insert([
            'import_id' => $import, 'source_record_number' => 3,
            'source_row_hash' => hash('sha256', 'fixture'), 'status' => 'inserted', 'journal_entry_id' => $journal,
        ]);
        self::assertSame(1, DB::table('journal_entries')->count());
        self::assertSame(2, DB::table('import_rows')->count());
    }

    public function test_successful_result_requires_a_journal(): void
    {
        $this->createUser();
        $import = DB::table('imports')->insertGetId($this->importData());
        $this->expectException(QueryException::class);
        DB::table('import_rows')->insert([
            'import_id' => $import, 'source_record_number' => 2,
            'source_row_hash' => hash('sha256', 'fixture'), 'status' => 'inserted',
        ]);
    }

    public function test_outbox_separates_publication_and_acknowledgment_and_prevents_duplicate_delivery(): void
    {
        $event = DB::table('outbox_events')->insertGetId([
            'event_type' => 'import.requested', 'event_version' => 1, 'payload' => '{"import_id":"123"}',
        ]);
        $delivery = ['event_id' => $event, 'consumer' => 'csv-importer'];
        DB::table('outbox_deliveries')->insert($delivery);
        DB::table('outbox_deliveries')->where('event_id', $event)->update(['published_at' => now(), 'status' => 'published']);
        self::assertNull(DB::table('outbox_deliveries')->value('acknowledged_at'));
        DB::table('outbox_deliveries')->where('event_id', $event)->update(['acknowledged_at' => now(), 'status' => 'acknowledged']);
        self::assertNotNull(DB::table('outbox_deliveries')->value('acknowledged_at'));

        $this->expectException(QueryException::class);
        DB::table('outbox_deliveries')->insert($delivery);
    }

    public function test_consumer_can_acknowledge_before_relay_records_publication(): void
    {
        $event = DB::table('outbox_events')->insertGetId(['event_type' => 'import.requested', 'event_version' => 1, 'payload' => '{}']);
        // Redis can deliver a job before the relay has committed published_at.
        DB::table('outbox_deliveries')->insert([
            'event_id' => $event, 'consumer' => 'csv-importer', 'status' => 'acknowledged', 'acknowledged_at' => now(),
        ]);
        self::assertNotNull(DB::table('outbox_deliveries')->value('acknowledged_at'));
        self::assertNull(DB::table('outbox_deliveries')->value('published_at'));
    }
}
