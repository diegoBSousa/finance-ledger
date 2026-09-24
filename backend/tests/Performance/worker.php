<?php

declare(strict_types=1);

use App\Infrastructure\Messaging\InvalidateDashboardJob;
use App\Infrastructure\Messaging\PrepareImportJob;
use App\Infrastructure\Messaging\ProcessImportChunkJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Performance\Guard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
Guard::check($app);
$directory = getenv('LEDGER_PERFORMANCE_OUTPUT');
if (! is_string($directory) || ! is_dir($directory)) {
    throw new RuntimeException('Missing performance output directory.');
}
$current = null;
Queue::before(function (JobProcessing $event) use (&$current): void {
    $payload = $event->job->payload();
    $job = unserialize($payload['data']['command'], ['allowed_classes' => [ProcessImportChunkJob::class, PrepareImportJob::class, InvalidateDashboardJob::class]]);
    $row = DB::table('outbox_deliveries as d')->join('outbox_events as e', 'e.id', '=', 'd.event_id')
        ->where('d.id', $job->deliveryId)->select('e.payload')->first();
    $data = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    $import = isset($data['import_id']) ? DB::table('imports')->find($data['import_id']) : null;
    memory_reset_peak_usage();
    $current = ['pid' => getmypid(), 'job' => $event->job->resolveName(), 'delivery_id' => $job->deliveryId,
        'import_id' => $data['import_id'] ?? null, 'checkpoint' => $data['checkpoint_version'] ?? null,
        'rows_before' => (int) ($import->processed_rows ?? 0), 'started' => hrtime(true),
        'query_count' => 0, 'query_ms' => 0.0];
});
DB::listen(function (QueryExecuted $query) use (&$current, $directory): void {
    if ($current === null) {
        return;
    }
    $current['query_count']++;
    $current['query_ms'] += $query->time;
    // Test-only fault barrier: after an INSERT executed inside the second chunk's transaction.
    // The supervisor kills this process. No production hooks, lease edits or shortened timers.
    $target = $directory.'/crash-target';
    if ($current['rows_before'] >= 500 && str_starts_with($query->sql, 'insert into `journal_entries`')
        && is_file($target) && trim(file_get_contents($target)) === $current['import_id'] && ! is_file($directory.'/crash-ready.json')) {
        file_put_contents($directory.'/crash-ready.json', json_encode($current, JSON_THROW_ON_ERROR), LOCK_EX);
        while (true) {
            usleep(100000);
        }
    }
});
$finish = function (string $status) use (&$current, $directory): void {
    if ($current === null) {
        return;
    }
    $record = $current;
    $current = null;
    $record['duration_ms'] = (hrtime(true) - $record['started']) / 1000000;
    unset($record['started']);
    $record['status'] = $status;
    $record['php_peak_bytes'] = memory_get_peak_usage(true);
    $record['rows_after'] = $record['import_id'] === null ? 0 : (int) DB::table('imports')->where('id', $record['import_id'])->value('processed_rows');
    file_put_contents($directory.'/jobs.jsonl', json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
};
Queue::after(fn (JobProcessed $event) => $finish('processed'));
Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => $finish('exception'));
exit($kernel->call('queue:work', ['connection' => 'redis', '--queue' => 'imports,default', '--sleep' => 1, '--tries' => 3,
    '--timeout' => 60, '--memory' => 128, '--max-time' => 7200, '--quiet' => true]));
