<?php

declare(strict_types=1);

use App\Application\Dashboard\Contracts\DashboardRepository;
use App\Application\Pagination\PageRequest;
use App\Application\Transactions\Contracts\LedgerReadRepository;
use App\Application\Transactions\Data\TransactionQueryData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Performance\Guard;
use Tests\Performance\HttpClient;
use Tests\Performance\SyntheticCsv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
Guard::check($app);
$directory = getenv('LEDGER_PERFORMANCE_OUTPUT');
if (! is_string($directory) || ! is_dir($directory) || ! is_writable($directory) || is_file($directory.'/report.json')) {
    throw new RuntimeException('Use a new writable output directory for each run.');
}
if (DB::select('SHOW TABLES') !== []) {
    throw new RuntimeException('Performance database must be empty. Start a new disposable Compose project.');
}
$quick = in_array('--quick', $argv, true);
$temporary = sys_get_temp_dir().'/ledger-performance-'.bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$processes = [];
$report = ['started_at' => gmdate(DATE_ATOM), 'mode' => $quick ? 'quick' : 'full', 'status' => 'running',
    'runtime' => ['php' => PHP_VERSION, 'mysql' => DB::selectOne('SELECT VERSION() AS version')->version,
        'innodb_buffer_pool_bytes' => DB::selectOne('SELECT @@innodb_buffer_pool_size AS bytes')->bytes,
        'mysql_session_timezone' => DB::selectOne('SELECT @@session.time_zone AS zone')->zone,
        'mysql_system_timezone' => DB::selectOne('SELECT @@system_time_zone AS zone')->zone,
        'worker_memory_limit' => '128M', 'workers' => 2, 'chunk_records' => 500],
    'scenarios' => [], 'latency_ms' => [], 'worker_rss_peak_bytes' => 0];
$expected = ['records' => 0, 'income_minor' => 0, 'expense_minor' => 0, 'accounts' => []];
$lastProgress = 0.0;

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function output(array $message): void
{
    global $report, $directory;
    // Keep evidence even if the supervisor or its environment is abruptly terminated.
    file_put_contents($directory.'/progress.json.tmp', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    rename($directory.'/progress.json.tmp', $directory.'/progress.json');
    echo json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    flush();
}

function startProcess(string $name, array $arguments): Process
{
    global $processes, $directory;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', ...$arguments], base_path(), timeout: null);
    $process->start(function (string $type, string $buffer) use ($directory, $name): void {
        file_put_contents($directory.'/'.$name.'.log', $buffer, FILE_APPEND);
    });
    $processes[$name] = $process;

    return $process;
}

function checkProcesses(): void
{
    global $processes, $report;
    foreach ($processes as $name => $process) {
        ensure($process->isRunning(), 'Process exited: '.$name.' (see its log).');
        $process->clearOutput();
        $process->clearErrorOutput();
        if (str_starts_with($name, 'worker')) {
            $status = @file_get_contents('/proc/'.$process->getPid().'/status');
            if (is_string($status) && preg_match('/^VmHWM:\s+(\d+) kB/m', $status, $match)) {
                $report['worker_rss_peak_bytes'] = max($report['worker_rss_peak_bytes'], (int) $match[1] * 1024);
            }
        }
    }
}

function until(callable $condition, int $timeout, string $description): void
{
    global $lastProgress;
    $start = microtime(true);
    do {
        checkProcesses();
        if ($condition()) {
            return;
        }
        if (microtime(true) - $lastProgress >= 10) {
            output(['waiting' => $description, 'seconds' => round(microtime(true) - $start, 1),
                'imports' => DB::table('imports')->select('id', 'status', 'processed_rows', 'inserted_rows', 'duplicate_rows')->get()->all()]);
            $lastProgress = microtime(true);
        }
        usleep(250000);
    } while (microtime(true) - $start < $timeout);
    throw new RuntimeException('Timed out: '.$description);
}

function totals(array $data): void
{
    global $expected;
    foreach (['records', 'income_minor', 'expense_minor'] as $key) {
        $expected[$key] += $data[$key];
    }
    foreach ($data['accounts'] as $account => $balance) {
        $expected['accounts'][$account] = ($expected['accounts'][$account] ?? 0) + $balance;
    }
}

function lockMetrics(): array
{
    $metrics = [];
    foreach (DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Innodb_deadlocks')") as $row) {
        $metrics[$row->Variable_name] = (int) $row->Value;
    }

    return $metrics;
}

function upload(string $label, string $path, array $shape): string
{
    global $http, $report;
    $started = microtime(true);
    $result = $http->upload($path)['data'];
    $id = $result['id'];
    unset($shape['accounts']);
    $report['scenarios'][$label] = ['import_id' => $id, 'shape' => $shape, 'started' => $started,
        'upload_seconds' => microtime(true) - $started, 'locks_before' => lockMetrics()];
    output(['uploaded' => $label, 'import_id' => $id, 'bytes' => $shape['bytes'], 'records' => $shape['records']]);

    return $id;
}

function complete(array $labels): void
{
    global $http, $report;
    $lastPoll = 0.0;
    $remaining = $labels;
    until(function () use (&$remaining, &$lastPoll, $http, &$report): bool {
        if (microtime(true) - $lastPoll < 1) {
            return false;
        }
        $lastPoll = microtime(true);
        foreach ($remaining as $index => $label) {
            $scenario = &$report['scenarios'][$label];
            $result = $http->get('/imports/'.$scenario['import_id'])['data'];
            ensure($result['status'] !== 'failed', 'Import failed: '.$label.' '.json_encode($result));
            if (in_array($result['status'], ['completed', 'completed_with_errors'], true)) {
                ensure((int) $result['processed_rows'] === $scenario['shape']['records'] && $result['rejected_rows'] === '0', 'Incorrect import counters: '.$label);
                $scenario['total_seconds'] = microtime(true) - $scenario['started'];
                $scenario['rows_per_second'] = $scenario['shape']['records'] / $scenario['total_seconds'];
                $scenario['result'] = $result;
                $after = lockMetrics();
                $scenario['lock_delta'] = array_map(fn ($value, $before) => $value - $before, $after, $scenario['locks_before']);
                $scenario['lock_delta'] = array_combine(array_keys($after), $scenario['lock_delta']);
                unset($scenario['started'], $scenario['locks_before'], $remaining[$index]);
                output(['completed' => $label, 'seconds' => round($scenario['total_seconds'], 3), 'result' => $result]);
            }
            unset($scenario);
        }

        return $remaining === [];
    }, 5400, 'import completion');
}

function ledgerAssertions(): void
{
    global $expected, $http;
    ensure(DB::table('journal_entries')->count() === $expected['records'], 'Incorrect journal count.');
    ensure(DB::table('ledger_entries')->count() === 2 * $expected['records'], 'Incorrect posting count.');
    ensure((int) DB::table('financial_states')->where('owner_user_id', $http->ownerId)->value('revision') === 2 * $expected['records'], 'Incorrect financial revision.');
    $invalid = DB::selectOne("SELECT COUNT(*) AS invalid FROM (SELECT journal_entry_id FROM ledger_entries GROUP BY journal_entry_id HAVING COUNT(*) <> 2 OR SUM(CASE WHEN side = 'debit' THEN amount_minor ELSE -amount_minor END) <> 0) AS checks");
    ensure((int) $invalid->invalid === 0, 'Unbalanced journal.');
    $actual = DB::table('ledger_entries as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.kind', 'asset')
        ->selectRaw("a.external_number, SUM(CASE WHEN l.side = 'debit' THEN l.amount_minor ELSE -l.amount_minor END) AS balance")
        ->groupBy('a.external_number')->get();
    ensure(count($actual) === count($expected['accounts']), 'Incorrect account count.');
    foreach ($actual as $account) {
        ensure((string) $account->balance === (string) $expected['accounts'][$account->external_number], 'Incorrect account balance.');
    }
}

function measure(string $name, callable $operation, int $samples = 5): mixed
{
    global $report;
    $durations = [];
    for ($i = 0; $i < $samples; $i++) {
        $start = hrtime(true);
        $result = $operation();
        $durations[] = (hrtime(true) - $start) / 1000000;
    }
    sort($durations);
    $report['latency_ms'][$name] = ['samples' => $samples, 'min' => min($durations), 'median' => $durations[intdiv($samples, 2)], 'max' => max($durations)];

    return $result;
}

try {
    foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        if (! is_dir(base_path($path))) {
            mkdir(base_path($path), 0775, true);
        }
    }
    ensure($kernel->call('migrate', ['--force' => true]) === 0, 'Migration failed.');
    ensure($kernel->call('db:seed', ['--force' => true]) === 0, 'Seed failed.');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    ensure(is_resource($socket), 'Cannot reserve HTTP port.');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    startProcess('api', ['-d', 'enable_post_data_reading=0', '-d', 'post_max_size=110000000', '-d', 'upload_max_filesize=100000000', '-d', 'max_file_uploads=1', '-S', '127.0.0.1:'.$port, '-t', 'public']);
    until(function () use ($port): bool {
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 0.1);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }, 15, 'HTTP startup');
    $http = new HttpClient($port);
    $http->login();
    startProcess('relay', ['artisan', 'outbox:relay', '--max-time=86400']);
    startProcess('worker-1', ['tests/Performance/worker.php']);
    startProcess('worker-2', ['tests/Performance/worker.php']);

    foreach (['memory-baseline' => $quick ? 1000 : 10000, 'scale' => $quick ? 2000 : 200000] as $label => $records) {
        $path = $temporary.'/'.$label.'.csv';
        $shape = SyntheticCsv::create($path, $label, $records, bytes: $records * 500);
        upload($label, $path, $shape);
        complete([$label]);
        ensure($report['scenarios'][$label]['result']['inserted_rows'] === (string) $records, 'Missing new rows.');
        totals($shape);
        ledgerAssertions();
    }
    // Overlapping files are both accepted before completion; two independent consumers compete.
    $overlapRows = $quick ? 1000 : 10000;
    foreach (['overlap-a' => 1, 'overlap-b' => 1 + intdiv($overlapRows, 2)] as $label => $start) {
        $path = $temporary.'/'.$label.'.csv';
        $shape = SyntheticCsv::create($path, 'overlap', $overlapRows, $start);
        upload($label, $path, $shape);
    }
    complete(['overlap-a', 'overlap-b']);
    $union = SyntheticCsv::create($temporary.'/overlap-union.csv', 'overlap', intdiv($overlapRows * 3, 2));
    totals($union);
    $a = $report['scenarios']['overlap-a']['result'];
    $b = $report['scenarios']['overlap-b']['result'];
    ensure((int) $a['inserted_rows'] + (int) $b['inserted_rows'] === $union['records'], 'Concurrent inserts are not the union.');
    ensure((int) $a['duplicate_rows'] + (int) $b['duplicate_rows'] === intdiv($overlapRows, 2), 'Concurrent duplicates are incorrect.');
    ledgerAssertions();

    // Replay a partial file through the same HTTP/outbox/Redis path.
    upload('partial-replay', $temporary.'/overlap-b.csv', SyntheticCsv::create($temporary.'/replay-control.csv', 'overlap', $overlapRows, 1 + intdiv($overlapRows, 2)));
    complete(['partial-replay']);
    ensure($report['scenarios']['partial-replay']['result']['inserted_rows'] === '0', 'Replay inserted rows.');
    ensure($report['scenarios']['partial-replay']['result']['duplicate_rows'] === (string) $overlapRows, 'Replay lost duplicates.');
    ledgerAssertions();

    // One consumer makes the crash point deterministic; the replacement uses unchanged production leases.
    foreach (['worker-1', 'worker-2'] as $name) {
        $processes[$name]->stop(65);
        unset($processes[$name]);
    }
    $path = $temporary.'/recovery.csv';
    $shape = SyntheticCsv::create($path, 'recovery', 2000);
    $id = upload('recovery', $path, $shape);
    file_put_contents($directory.'/crash-target', $id);
    $victim = startProcess('worker-victim', ['tests/Performance/worker.php']);
    until(fn () => is_file($directory.'/crash-ready.json'), 60, 'crash barrier inside transaction');
    $beforeCrash = DB::table('imports')->find($id);
    ensure((int) $beforeCrash->processed_rows === 500 && $beforeCrash->lease_token !== null, 'Expected committed first chunk and leased second chunk.');
    ensure(DB::table('journal_entries')->count() === $expected['records'] + 500, 'Uncommitted rows became visible.');
    $crashStarted = microtime(true);
    $victim->stop(0, SIGKILL);
    unset($processes['worker-victim']);
    $report['recovery'] = ['signal' => 'SIGKILL', 'committed_rows_before' => 500, 'lease_expires_at' => $beforeCrash->lease_expires_at,
        'timers_modified' => false, 'reserved_queue_job_preserved' => true];
    ensure(DB::table('import_rows')->where('import_id', $id)->count() === 500, 'In-flight checkpoint leaked results.');
    startProcess('worker-replacement', ['tests/Performance/worker.php']);
    complete(['recovery']);
    $report['recovery']['seconds_after_kill'] = microtime(true) - $crashStarted;
    ensure($report['scenarios']['recovery']['result']['inserted_rows'] === '2000', 'Recovery lost or duplicated rows.');
    ensure($report['scenarios']['recovery']['result']['duplicate_rows'] === '0', 'Rollback was not atomic.');
    totals($shape);
    ledgerAssertions();

    until(fn () => DB::table('outbox_deliveries')->whereIn('consumer', ['csv-importer', 'import-preparer', 'dashboard-cache-invalidator'])->where('status', '<>', 'acknowledged')->count() === 0, 330, 'outbox acknowledgements');
    ensure(DB::table('failed_jobs')->count() === 0, 'Unexpected failed queue jobs.');
    $dashboard = measure('dashboard-first-read', fn () => $http->get('/dashboard'), 1)['data'];
    ensure($dashboard['income_minor'] === (string) $expected['income_minor'] && $dashboard['expense_minor'] === (string) $expected['expense_minor'], 'Incorrect dashboard totals.');
    ensure($dashboard['transaction_count'] === (string) $expected['records'], 'Incorrect dashboard operation count.');
    measure('dashboard-cached', fn () => $http->get('/dashboard'));
    ensure((int) DB::table('account_balances as b')->join('accounts as a', 'a.id', '=', 'b.account_id')->where('a.external_number', 682)->value('b.staled') === 1, 'Expected stale balance before read.');
    $balance = measure('staled-balance-read', fn () => $http->get('/accounts/682/balance'), 1)['data'];
    ensure($balance['balance_minor'] === (string) $expected['accounts'][682], 'Incorrect recalculated balance.');
    ensure($balance['ledger_version'] === $balance['calculated_version'], 'Balance read returned an old projection.');
    measure('fresh-balance-read', fn () => $http->get('/accounts/682/balance'));
    foreach (['transactions-first-page' => '/transactions', 'transactions-deep-page' => '/transactions?page='.intdiv($expected['records'], 10),
        'account-transactions' => '/accounts/682/transactions', 'balances-page' => '/balances',
        'import-rows' => '/imports/'.$id.'/rows'] as $label => $uri) {
        $page = measure($label, fn () => $http->get($uri));
        ensure(count($page['data']) <= 10 && $page['meta']['per_page'] === 10, 'Pagination exceeds ten items.');
    }
    $http->get('/transactions?per_page=11', 422);

    $projectStart = microtime(true);
    startProcess('projector', ['artisan', 'balances:project', '--max-time=3600']);
    until(fn () => DB::table('account_balances')->where('staled', 1)->orWhereColumn('ledger_version', '<>', 'calculated_version')->count() === 0, 300, 'balance projection');
    $report['projector_drain_seconds'] = microtime(true) - $projectStart;
    $processes['projector']->stop(10);
    unset($processes['projector']);
    foreach (DB::table('account_balances as b')->join('accounts as a', 'a.id', '=', 'b.account_id')->where('a.kind', 'asset')->select('a.external_number', 'b.balance_minor')->get() as $row) {
        ensure((string) $row->balance_minor === (string) ($expected['accounts'][$row->external_number] ?? 0), 'Projector returned incorrect balance.');
    }

    // Capture the adapters' real SQL, with bindings, then explain only their SELECTs.
    $queries = [];
    $capture = true;
    DB::listen(function (QueryExecuted $query) use (&$queries, &$capture): void {
        if ($capture && str_starts_with(strtolower($query->sql), 'select')) {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'observed_ms' => $query->time];
        }
    });
    $app->make(LedgerReadRepository::class)->page(new TransactionQueryData($http->ownerId, new PageRequest));
    $app->make(LedgerReadRepository::class)->page(new TransactionQueryData($http->ownerId, new PageRequest(intdiv($expected['records'], 10))));
    $app->make(LedgerReadRepository::class)->page(new TransactionQueryData($http->ownerId, new PageRequest, '682'));
    $app->make(DashboardRepository::class)->snapshot($http->ownerId);
    $capture = false;
    foreach ($queries as &$query) {
        $query['plan'] = json_decode((array_values((array) DB::selectOne('EXPLAIN FORMAT=JSON '.$query['sql'], $query['bindings'])))[0], true, flags: JSON_THROW_ON_ERROR);
    }
    unset($query);
    file_put_contents($directory.'/explain.json', json_encode($queries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

    // Stop workers before reading the append-only metrics file.
    foreach ($processes as $name => $process) {
        if (str_starts_with($name, 'worker')) {
            $process->stop(65);
            unset($processes[$name]);
        }
    }
    $jobs = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($directory.'/jobs.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    foreach ($report['scenarios'] as &$scenario) {
        $chunks = array_values(array_filter($jobs, fn ($job) => $job['import_id'] === $scenario['import_id'] && $job['checkpoint'] !== null && $job['rows_after'] > $job['rows_before']));
        ensure($chunks !== [], 'Missing chunk telemetry.');
        $durations = array_column($chunks, 'duration_ms');
        sort($durations);
        $scenario['chunks'] = ['count' => count($chunks), 'duration_ms_p50' => $durations[(int) floor((count($durations) - 1) * 0.5)],
            'duration_ms_p95' => $durations[(int) ceil((count($durations) - 1) * 0.95)], 'duration_ms_max' => max($durations),
            'query_count' => array_sum(array_column($chunks, 'query_count')), 'query_ms' => array_sum(array_column($chunks, 'query_ms')),
            'php_peak_bytes' => max(array_column($chunks, 'php_peak_bytes'))];
    }
    unset($scenario);
    $report['job_exceptions'] = count(array_filter($jobs, fn ($job) => $job['status'] !== 'processed'));
    $report['controls'] = array_diff_key($expected, ['accounts' => true]);
    $report['controls']['balance_minor'] = $expected['income_minor'] - $expected['expense_minor'];
    $report['controls']['ledger_entries'] = 2 * $expected['records'];
    $report['status'] = 'passed';
    output(['status' => 'passed', 'report' => $directory.'/report.json', 'controls' => $report['controls']]);
} catch (Throwable $exception) {
    $report['status'] = 'failed';
    $report['failure'] = $exception->getMessage();
    fwrite(STDERR, $exception->getMessage()."\n".$exception->getTraceAsString()."\n");
} finally {
    foreach ($processes as $process) {
        $process->stop(10);
    }
    foreach (glob($temporary.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($temporary);
    $report['finished_at'] = gmdate(DATE_ATOM);
    file_put_contents($directory.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
}
exit($report['status'] === 'passed' ? 0 : 1);
