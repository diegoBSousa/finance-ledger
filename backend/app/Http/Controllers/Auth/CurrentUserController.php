<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Auth\CurrentUserUseCase;
use App\Application\Auth\Data\CurrentUserRequest;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\AuthPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentUserController
{
    public function __invoke(Request $request, CurrentUserUseCase $currentUser): JsonResponse
    {
        $response = $currentUser->execute(new CurrentUserRequest(AuthenticatedContext::fromRequest($request)));

        return response()->json(['data' => AuthPresenter::user($response->user)]);
    }
}
