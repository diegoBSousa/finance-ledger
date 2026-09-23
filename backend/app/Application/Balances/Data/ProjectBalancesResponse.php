<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

final readonly class ProjectBalancesResponse
{
    public function __construct(
        public string $nextAccountId,
        public int $examined,
        public int $recalculated,
        public int $skipped,
        public int $failed,
        public int $pendingBefore,
        public ?string $oldestStaledAt,
    ) {}
}
