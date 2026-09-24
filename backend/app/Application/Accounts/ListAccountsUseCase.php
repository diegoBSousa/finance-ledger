<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\Accounts\Data\ListAccountsRequest;
use App\Application\Accounts\Data\ListAccountsResponse;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\BalancePageQueryData;
use App\Application\Shared\ReadUnavailable;

final readonly class ListAccountsUseCase
{
    public function __construct(private BalanceRepository $repository) {}

    public function execute(ListAccountsRequest $request): ListAccountsResponse
    {
        $page = $this->repository->page(new BalancePageQueryData($request->actorUserId, $request->pagination, $request->accountNumber));
        if (count($page->accounts) > $request->pagination->perPage) {
            throw new ReadUnavailable;
        }
        foreach ($page->accounts as $account) {
            if ($account->ownerUserId !== $request->actorUserId || $account->kind !== 'asset') {
                throw new ReadUnavailable;
            }
        }

        return new ListAccountsResponse($page->accounts, $page->total, $request->pagination);
    }
}
