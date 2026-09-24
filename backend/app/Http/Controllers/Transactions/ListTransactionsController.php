<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transactions;

use App\Application\Pagination\PageRequest;
use App\Application\Transactions\Data\ListTransactionsRequest;
use App\Application\Transactions\Data\TransactionQueryData;
use App\Application\Transactions\ListTransactionsUseCase;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\TransactionPresenter;
use App\Http\Requests\TransactionListFormRequest;
use Illuminate\Http\JsonResponse;

final class ListTransactionsController
{
    public function __invoke(TransactionListFormRequest $request, ListTransactionsUseCase $list): JsonResponse
    {
        $input = $request->validated();
        $response = $list->execute(new ListTransactionsRequest(new TransactionQueryData(AuthenticatedContext::fromRequest($request)->user->id,
            new PageRequest((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10)), $input['account_number'] ?? null,
            $input['date_from'] ?? null, $input['date_to'] ?? null, $input['type'] ?? null)));

        return response()->json(TransactionPresenter::page($response));
    }
}
