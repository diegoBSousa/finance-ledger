<?php

declare(strict_types=1);

use App\Application\Balances\BalanceUnavailable;
use App\Application\Balances\Data\RefreshAccountBalanceRequest;
use App\Application\Balances\RefreshAccountBalanceUseCase;
use App\Infrastructure\Persistence\MysqlBalanceRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Support\MysqlDatabase;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = MysqlDatabase::application();
$options = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$connection = DB::connection(MysqlBalanceRepository::CONNECTION);
$connection->statement('SET SESSION innodb_lock_wait_timeout = '.(! empty($options['timeout']) ? '1' : '5'));
$attempts = 0;
$app->make('events')->listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$attempts): void {
    if ($event->connectionName === MysqlBalanceRepository::CONNECTION) {
        $attempts++;
    }
});
if (isset($options['release_file'])) {
    $paused = false;
    DB::listen(function (QueryExecuted $query) use ($options, &$paused): void {
        if (! $paused && $query->connectionName === MysqlBalanceRepository::CONNECTION
            && str_contains(strtolower($query->sql), 'for update')) {
            $paused = true;
            echo "locked\n";
            flush();
            $deadline = microtime(true) + 10;
            while (! file_exists($options['release_file'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Test barrier timed out.');
                }
                usleep(10000);
            }
        }
    });
}
echo "ready\n";
flush();
try {
    $result = $app->make(RefreshAccountBalanceUseCase::class)->execute(new RefreshAccountBalanceRequest('7', '42'));
    echo json_encode(['status' => 'ok', 'result' => $result, 'attempts' => $attempts], JSON_THROW_ON_ERROR)."\n";
} catch (BalanceUnavailable) {
    echo json_encode(['status' => 'unavailable', 'attempts' => $attempts], JSON_THROW_ON_ERROR)."\n";
} finally {
    $connection->disconnect();
    DB::disconnect();
}
