<?php

declare(strict_types=1);

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostingBatchData;
use Illuminate\Support\Facades\DB;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\MysqlDatabase;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Own process and connection; the same test-database safety guards apply, without resetting it.
$app = MysqlDatabase::application();
$descriptions = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
DB::beginTransaction();
try {
    DB::table('journal_entries')->count(); // Establish an old snapshot before the parent releases the writer lock.
    echo "ready\n";
    flush();
    $batch = new PostingBatchData('7', array_map(fn (string $description) => PostingBatches::entry($description), $descriptions));
    $result = $app->make(JournalRepository::class)->post($batch);
    DB::commit();
    echo json_encode($result->entries, JSON_THROW_ON_ERROR)."\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect();
}
