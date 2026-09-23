<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Auth\Data\LogoutRequest;
use App\Application\Auth\LogoutUseCase;
use App\Http\AuthenticatedContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogoutController
{
    public function __invoke(Request $request, LogoutUseCase $logout): JsonResponse
    {
        $response = $logout->execute(new LogoutRequest(AuthenticatedContext::fromRequest($request)));

        return response()->json(['data' => ['revoked' => $response->revoked]]);
    }
}
