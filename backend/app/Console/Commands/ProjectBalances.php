<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Balances\BalanceUnavailable;
use App\Application\Balances\Data\ProjectBalancesRequest;
use App\Application\Balances\ProjectBalancesUseCase;
use DateTimeImmutable;
use Illuminate\Console\Command;

final class ProjectBalances extends Command
{
    protected $signature = 'balances:project {--once : Process at most ten pending accounts and exit} {--sleep=1 : Seconds between batches (1-60)} {--max-time=3600 : Restart after this many seconds (1-86400)}';

    protected $description = 'Refresh pending balance projections independently of Redis';

    public function handle(ProjectBalancesUseCase $project): int
    {
        $sleep = filter_var($this->option('sleep'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
        $maxTime = filter_var($this->option('max-time'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
        if ($sleep === false || $maxTime === false) {
            $this->error('Invalid sleep or max-time option.');

            return self::INVALID;
        }
        $stop = false;
        $this->trap([SIGTERM, SIGINT], function () use (&$stop): void {
            $stop = true;
        });
        $cursor = '0';
        $started = microtime(true);
        do {
            $batchStart = microtime(true);
            $failed = 0;
            try {
                $result = $project->execute(new ProjectBalancesRequest($cursor));
                $cursor = $result->nextAccountId;
                $failed = $result->failed;
                $this->line(json_encode([
                    'event' => 'balances.projected', 'examined' => $result->examined,
                    'recalculated' => $result->recalculated, 'skipped' => $result->skipped, 'failed' => $failed,
                    'pending_before' => $result->pendingBefore, 'oldest_staled_at' => $result->oldestStaledAt,
                    'oldest_staled_age_seconds' => $result->oldestStaledAt === null ? null
                        : max(0, time() - (new DateTimeImmutable($result->oldestStaledAt))->getTimestamp()),
                    'duration_ms' => round((microtime(true) - $batchStart) * 1000, 2),
                ], JSON_THROW_ON_ERROR));
            } catch (BalanceUnavailable) {
                $failed = 1;
                $this->error('{"event":"balances.unavailable"}');
            }
            if ($this->option('once')) {
                return $failed > 0 ? self::FAILURE : self::SUCCESS;
            }
            if (! $stop && microtime(true) - $started < $maxTime) {
                sleep($sleep);
            }
        } while (! $stop && microtime(true) - $started < $maxTime);

        return self::SUCCESS;
    }
}
