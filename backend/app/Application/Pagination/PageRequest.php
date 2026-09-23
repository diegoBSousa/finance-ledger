<?php

declare(strict_types=1);

namespace App\Application\Pagination;

use App\Domain\Shared\DomainViolation;

final readonly class PageRequest
{
    public const int MAX_PER_PAGE = 10;

    public function __construct(public int $page = 1, public int $perPage = self::MAX_PER_PAGE)
    {
        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new DomainViolation('invalid_pagination', 'Page must be positive and perPage must be between 1 and 10.');
        }

        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new DomainViolation('pagination_overflow', 'The requested offset exceeds the signed integer range.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
