<?php

declare(strict_types=1);

namespace App\Application\Accounts\Data;

use App\Application\Pagination\PageRequest;

final readonly class ListAccountsRequest
{
    public function __construct(public string $actorUserId, public PageRequest $pagination, public ?string $accountNumber = null) {}
}
