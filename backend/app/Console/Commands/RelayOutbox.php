<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Outbox\OutboxUnavailable;
use App\Application\Outbox\RelayOutboxUseCase;
use Illuminate\Console\Command;

final class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--once : Publish at most ten eligible deliveries} {--max-time=3600 : Restart after this many seconds}';

    protected $description = 'Publish durable outbox deliveries to Redis and recover expired leases';

    public function handle(RelayOutboxUseCase $relay): int
    {
        $max = filter_var($this->option('max-time'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if ($max === false) {
            $this->error('Invalid max-time.');

            return self::INVALID;
        }
        $stop = false;
        $started = microtime(true);
        $this->trap([SIGTERM, SIGINT], function () use (&$stop): void {
            $stop = true;
        });
        do {
            $failed = false;
            try {
                $result = $relay->execute();
                $failed = $result->retrying > 0;
                $this->line(json_encode(['event' => 'outbox.relay', 'claimed' => $result->claimed, 'published' => $result->published, 'retrying' => $result->retrying], JSON_THROW_ON_ERROR));
            } catch (OutboxUnavailable) {
                $failed = true;
                $this->error('{"event":"outbox.unavailable"}');
            }
            if ($this->option('once')) {
                return $failed ? self::FAILURE : self::SUCCESS;
            }
            if (! $stop) {
                sleep(1);
            }
        } while (! $stop && microtime(true) - $started < $max);

        return self::SUCCESS;
    }
}
