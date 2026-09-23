<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Domain\Shared\Currency;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

final class MoneyTest extends TestCase
{
    public function test_decimal_cents_are_exact_and_not_scaled(): void
    {
        self::assertSame('494618', Money::positiveFromDecimal('000494618')->toDecimal());
        self::assertSame('9007199254740993', Money::fromDecimal('9007199254740993')->toDecimal());
        self::assertSame('0', Money::fromDecimal('-000')->toDecimal());
        self::assertSame((string) PHP_INT_MIN, Money::fromDecimal((string) PHP_INT_MIN)->toDecimal());
        self::assertSame((string) PHP_INT_MAX, Money::fromDecimal((string) PHP_INT_MAX)->toDecimal());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAmounts(): iterable
    {
        foreach (['', '0', '-0', '-1', '+1', '1.5', '1,5', '1e3', 'NaN', 'INF', '0x10', '1_000', '1 000', ' 1', '１', '9223372036854775808'] as $value) {
            yield 'invalid '.$value => [$value];
        }
    }

    #[DataProvider('invalidAmounts')]
    public function test_import_amounts_must_be_positive_integer_cents(string $value): void
    {
        $this->expectException(DomainViolation::class);
        Money::positiveFromDecimal($value);
    }

    public function test_float_is_not_an_accepted_internal_amount(): void
    {
        $this->expectException(TypeError::class);
        Money::fromMinor(1.5);
    }

    public function test_negative_balances_and_boundary_arithmetic_are_exact(): void
    {
        $original = Money::fromMinor(100);
        self::assertSame('-150', $original->subtract(Money::fromMinor(250))->toDecimal());
        self::assertSame('100', $original->toDecimal());
        self::assertSame('-1', Money::fromMinor(PHP_INT_MIN)->add(Money::fromMinor(PHP_INT_MAX))->toDecimal());
        self::assertSame('0', Money::fromMinor(PHP_INT_MIN)->subtract(Money::fromMinor(PHP_INT_MIN))->toDecimal());
        self::assertSame((string) PHP_INT_MAX, Money::fromMinor(-1)->subtract(Money::fromMinor(PHP_INT_MIN))->toDecimal());
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function overflowingOperations(): iterable
    {
        yield 'addition upper' => [PHP_INT_MAX, 1, 'add'];
        yield 'addition lower' => [PHP_INT_MIN, -1, 'add'];
        yield 'subtraction lower' => [PHP_INT_MIN, 1, 'subtract'];
        yield 'subtraction upper' => [PHP_INT_MAX, -1, 'subtract'];
        yield 'negating minimum' => [0, PHP_INT_MIN, 'subtract'];
    }

    #[DataProvider('overflowingOperations')]
    public function test_overflow_is_rejected_before_float_conversion(int $left, int $right, string $operation): void
    {
        $this->expectException(DomainViolation::class);
        Money::fromMinor($left)->{$operation}(Money::fromMinor($right));
    }

    public function test_negative_decimal_underflow_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        Money::fromDecimal('-9223372036854775809');
    }

    public function test_unsupported_currency_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        Currency::fromCode('USD');
    }
}
