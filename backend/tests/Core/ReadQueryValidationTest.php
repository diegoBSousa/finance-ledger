<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Pagination\PageRequest;
use App\Application\Transactions\Data\TransactionQueryData;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadQueryValidationTest extends TestCase
{
    #[DataProvider('invalidFilters')]
    public function test_internal_transaction_queries_enforce_filters(?string $account, ?string $from, ?string $to, ?string $type): void
    {
        $this->expectException(DomainViolation::class);
        new TransactionQueryData('7', new PageRequest, $account, $from, $to, $type);
    }

    public static function invalidFilters(): iterable
    {
        yield ['0682', null, null, null];
        yield ['-1', null, null, null];
        yield [null, '2026-02-30', null, null];
        yield [null, '2026-08-17', '2026-08-16', null];
        yield [null, null, null, 'Receita'];
        yield [null, '2026-8-1', null, null];
    }

    public function test_inclusive_iso_range_and_business_type_are_preserved(): void
    {
        $q = new TransactionQueryData('7', new PageRequest, '682', '2024-02-29', '2024-02-29', 'income');
        self::assertSame($q->dateFrom, $q->dateTo);
        self::assertSame('income', $q->type);
    }
}
