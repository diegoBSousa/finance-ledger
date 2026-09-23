<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Shared\DecimalInteger;

final readonly class AccountNumber
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = (string) DecimalInteger::positive($value);
    }
}
