<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Shared\DomainViolation;

final readonly class PostingDate
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new DomainViolation('invalid_date', 'Expected a real calendar date in YYYY-MM-DD format.');
        }
    }
}
