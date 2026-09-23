<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Shared\Contracts\Clock;
use Illuminate\Console\Command;

final class PruneRevokedTokens extends Command
{
    protected $signature = 'auth:prune-revoked-tokens';

    protected $description = 'Remove revocations only after their JWT has expired';

    public function handle(TokenRevocationRepository $revocations, Clock $clock): int
    {
        $this->info('Expired revocations removed: '.$revocations->pruneExpired($clock->now()));

        return self::SUCCESS;
    }
}
