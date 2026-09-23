<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

final readonly class AccountData
{
    public function __construct(
        public string $id,
        public string $ownerUserId,
        public ?string $externalNumber,
        public string $kind,
        public string $currency = 'BRL',
        public bool $active = true,
    ) {}
}
