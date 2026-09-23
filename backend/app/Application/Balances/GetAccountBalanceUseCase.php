<?php

declare(strict_types=1);

namespace App\Application\Balances;

use App\Application\Balances\Data\GetAccountBalanceRequest;
use App\Application\Balances\Data\GetAccountBalanceResponse;
use App\Application\Balances\Data\ListAccountBalancesRequest;
use App\Application\Pagination\PageRequest;

final readonly class GetAccountBalanceUseCase
{
    public function __construct(private ListAccountBalancesUseCase $list) {}

    public function execute(GetAccountBalanceRequest $request): GetAccountBalanceResponse
    {
        $page = $this->list->execute(new ListAccountBalancesRequest($request->actorUserId, new PageRequest(1, 1), $request->accountNumber));
        if ($page->balances === []) {
            throw new AccountNotFound;
        }

        return new GetAccountBalanceResponse($page->balances[0]);
    }
}
