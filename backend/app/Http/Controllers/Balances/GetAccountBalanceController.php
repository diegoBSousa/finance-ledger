<?php

declare(strict_types=1);

namespace App\Http\Controllers\Balances;

use App\Application\Balances\AccountNotFound;
use App\Application\Balances\Data\GetAccountBalanceRequest;
use App\Application\Balances\GetAccountBalanceUseCase;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\BalancePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GetAccountBalanceController
{
    public function __invoke(Request $request, string $accountNumber, GetAccountBalanceUseCase $get): JsonResponse
    {
        try {
            DecimalInteger::positive($accountNumber);
        } catch (DomainViolation) {
            throw new AccountNotFound;
        }
        $response = $get->execute(new GetAccountBalanceRequest(AuthenticatedContext::fromRequest($request)->user->id, $accountNumber));

        return response()->json(['data' => BalancePresenter::balance($response->balance)]);
    }
}
