<?php

declare(strict_types=1);

namespace App\Http\Controllers\Balances;

use App\Application\Balances\Data\ListAccountBalancesRequest;
use App\Application\Balances\ListAccountBalancesUseCase;
use App\Application\Pagination\PageRequest;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\BalancePresenter;
use App\Http\Requests\BalanceListFormRequest;
use Illuminate\Http\JsonResponse;

final class ListAccountBalancesController
{
    public function __invoke(BalanceListFormRequest $request, ListAccountBalancesUseCase $list): JsonResponse
    {
        $input = $request->validated();
        $response = $list->execute(new ListAccountBalancesRequest(
            AuthenticatedContext::fromRequest($request)->user->id,
            new PageRequest((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10)),
            $input['account_number'] ?? null,
        ));

        return response()->json(BalancePresenter::page($response));
    }
}
