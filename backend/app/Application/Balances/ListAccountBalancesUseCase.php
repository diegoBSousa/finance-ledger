<?php

declare(strict_types=1);

namespace App\Application\Balances;

use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\BalancePageQueryData;
use App\Application\Balances\Data\ListAccountBalancesRequest;
use App\Application\Balances\Data\ListAccountBalancesResponse;
use App\Application\Balances\Data\RefreshAccountBalanceRequest;

final readonly class ListAccountBalancesUseCase
{
    public function __construct(private BalanceRepository $balances, private RefreshAccountBalanceUseCase $refresh) {}

    public function execute(ListAccountBalancesRequest $request): ListAccountBalancesResponse
    {
        $query = new BalancePageQueryData($request->actorUserId, $request->pagination, $request->accountNumber);
        $page = $this->balances->page($query);
        if (count($page->accounts) > $request->pagination->perPage) {
            throw new BalanceUnavailable;
        }
        $balances = [];
        foreach ($page->accounts as $account) {
            if ($account->ownerUserId !== $query->ownerUserId || $account->kind !== 'asset') {
                throw new BalanceUnavailable;
            }
            // Lock and recheck even projections that appeared fresh when selecting the page.
            $balance = $this->refresh->execute(new RefreshAccountBalanceRequest($query->ownerUserId, $account->id))->balance;
            if ($balance === null) {
                throw new BalanceUnavailable;
            }
            $balances[] = $balance;
        }

        return new ListAccountBalancesResponse($balances, $request->pagination, $page->total);
    }
}
