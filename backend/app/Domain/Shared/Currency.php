<?php

declare(strict_types=1);

namespace App\Domain\Shared;

enum Currency: string
{
    case BRL = 'BRL';

    public static function fromCode(string $code): self
    {
        return self::tryFrom($code)
            ?? throw new DomainViolation('unsupported_currency', 'Only BRL is supported.');
    }
}
