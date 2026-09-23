<?php

declare(strict_types=1);

namespace App\Domain\Shared;

final class DecimalInteger
{
    public static function parse(string $value): int
    {
        if (PHP_INT_SIZE !== 8) {
            throw new DomainViolation('unsupported_platform', 'The ledger requires 64-bit PHP.');
        }

        if (preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            throw new DomainViolation('invalid_integer', 'Expected a decimal integer string.');
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new DomainViolation('integer_overflow', 'The integer exceeds the signed 64-bit range.');
        }

        return (int) (($negative && $digits !== '0' ? '-' : '').$digits);
    }

    public static function positive(string $value): int
    {
        if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new DomainViolation('invalid_positive_integer', 'Expected positive decimal digits.');
        }

        $integer = self::parse($value);

        if ($integer <= 0) {
            throw new DomainViolation('invalid_positive_integer', 'The integer must be greater than zero.');
        }

        return $integer;
    }
}
