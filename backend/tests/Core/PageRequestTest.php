<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Pagination\PageRequest;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageRequestTest extends TestCase
{
    public function test_default_and_maximum_page_size_are_ten(): void
    {
        self::assertSame(10, (new PageRequest)->perPage);
        self::assertSame(0, (new PageRequest)->offset());
        self::assertSame(20, (new PageRequest(3, 10))->offset());
        self::assertSame(2, (new PageRequest(3, 1))->offset());
    }

    /** @return iterable<array{int, int}> */
    public static function invalidPages(): iterable
    {
        yield [0, 10];
        yield [-1, 10];
        yield [1, 0];
        yield [1, 11];
        yield [1, 100];
        yield [PHP_INT_MAX, 10];
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_page_or_size_is_rejected(int $page, int $perPage): void
    {
        $this->expectException(DomainViolation::class);
        new PageRequest($page, $perPage);
    }
}
