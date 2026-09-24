<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Application\Dashboard\Data\GetDashboardRequest;
use App\Application\Dashboard\GetDashboardUseCase;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\DashboardPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GetDashboardController
{
    public function __invoke(Request $request, GetDashboardUseCase $get): JsonResponse
    {
        $response = $get->execute(new GetDashboardRequest(AuthenticatedContext::fromRequest($request)->user->id));

        return response()->json(DashboardPresenter::present($response));
    }
}
