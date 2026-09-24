<?php

declare(strict_types=1);

namespace App\Application\Transactions;

use App\Application\Shared\ReadUnavailable;
use App\Application\Transactions\Contracts\LedgerReadRepository;
use App\Application\Transactions\Data\ListTransactionsRequest;
use App\Application\Transactions\Data\ListTransactionsResponse;

final readonly class ListTransactionsUseCase
{
    public function __construct(private LedgerReadRepository $repository) {}

    public function execute(ListTransactionsRequest $request): ListTransactionsResponse
    {
        $query = $request->query;
        $page = $this->repository->page($query);
        if (count($page->transactions) > $query->pagination->perPage) {
            throw new ReadUnavailable;
        }
        foreach ($page->transactions as $row) {
            if ($row->ownerUserId !== $query->actorUserId || ($query->accountNumber !== null && $row->accountNumber !== $query->accountNumber)) {
                throw new ReadUnavailable;
            }
        }

        return new ListTransactionsResponse($page, $query->pagination);
    }
}
