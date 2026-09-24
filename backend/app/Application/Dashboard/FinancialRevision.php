<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Domain\Shared\DomainViolation;

final class FinancialRevision
{
    public static function validate(string $revision): string
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/', $revision) !== 1 || self::compare($revision, '18446744073709551615') > 0) {
            throw new DomainViolation('invalid_revision', 'Expected an unsigned 64-bit revision.');
        }

        return $revision;
    }

    public static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
