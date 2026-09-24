<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Pagination\PageRequest;

final class PagePresenter
{
    /** @return array<string,int|bool> */
    public static function meta(PageRequest $page, int $total): array
    {
        $last = max(1, intdiv($total, $page->perPage) + (int) ($total % $page->perPage !== 0));

        return ['current_page' => $page->page, 'per_page' => $page->perPage, 'total' => $total, 'last_page' => $last, 'has_next_page' => $page->page < $last];
    }
}
