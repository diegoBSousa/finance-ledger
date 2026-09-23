<?php

declare(strict_types=1);

namespace App\Domain\Shared;

final readonly class Money
{
    private function __construct(public int $minor, public Currency $currency) {}

    public static function fromMinor(int $minor, Currency $currency = Currency::BRL): self
    {
        if (PHP_INT_SIZE !== 8) {
            throw new DomainViolation('unsupported_platform', 'The ledger requires 64-bit PHP.');
        }

        return new self($minor, $currency);
    }

    public static function fromDecimal(string $minor, Currency $currency = Currency::BRL): self
    {
        return new self(DecimalInteger::parse($minor), $currency);
    }

    public static function positiveFromDecimal(string $minor): self
    {
        return new self(DecimalInteger::positive($minor), Currency::BRL);
    }

    public function add(self $other): self
    {
        if (($other->minor > 0 && $this->minor > PHP_INT_MAX - $other->minor)
            || ($other->minor < 0 && $this->minor < PHP_INT_MIN - $other->minor)) {
            throw new DomainViolation('money_overflow', 'Adding the amounts would exceed the signed 64-bit range.');
        }

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        if (($other->minor > 0 && $this->minor < PHP_INT_MIN + $other->minor)
            || ($other->minor < 0 && $this->minor > PHP_INT_MAX + $other->minor)) {
            throw new DomainViolation('money_overflow', 'Subtracting the amounts would exceed the signed 64-bit range.');
        }

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function toDecimal(): string
    {
        return (string) $this->minor;
    }
}
