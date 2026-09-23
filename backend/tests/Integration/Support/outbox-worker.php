<?php

declare(strict_types=1);

use App\Application\Imports\Data\PrepareImportRequest;
use App\Application\Imports\PrepareImportUseCase;
use App\Application\Outbox\Contracts\OutboxRepository;
use Tests\Integration\Support\MysqlDatabase;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = MysqlDatabase::application();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
echo "ready\n";
flush();
$deadline = microtime(true) + 10;
while (! file_exists($input['barrier'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test barrier timed out.');
    }usleep(10000);
}
$result = isset($input['delivery']) ? $app->make(PrepareImportUseCase::class)->execute(new PrepareImportRequest($input['delivery'])) : $app->make(OutboxRepository::class)->claim();
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
