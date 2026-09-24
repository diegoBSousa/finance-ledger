<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use App\Application\Accounts\Data\ListAccountsRequest;
use App\Application\Accounts\ListAccountsUseCase;
use App\Application\Pagination\PageRequest;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\AccountPresenter;
use App\Http\Requests\BalanceListFormRequest;
use Illuminate\Http\JsonResponse;

final class ListAccountsController
{
    public function __invoke(BalanceListFormRequest $request, ListAccountsUseCase $list): JsonResponse
    {
        $input = $request->validated();
        $response = $list->execute(new ListAccountsRequest(AuthenticatedContext::fromRequest($request)->user->id,
            new PageRequest((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10)), $input['account_number'] ?? null));

        return response()->json(AccountPresenter::page($response));
    }
}
