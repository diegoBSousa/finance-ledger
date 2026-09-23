<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

use App\Domain\Shared\DecimalInteger;

final readonly class ProjectBalancesRequest
{
    public string $afterAccountId;

    public function __construct(string $afterAccountId = '0')
    {
        $this->afterAccountId = $afterAccountId === '0' ? '0' : (string) DecimalInteger::positive($afterAccountId);
    }
}
