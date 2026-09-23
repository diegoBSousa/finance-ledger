<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Auth\Data\LoginRequest;
use App\Application\Auth\LoginUseCase;
use App\Http\Presenters\AuthPresenter;
use App\Http\Requests\LoginFormRequest;
use Illuminate\Http\JsonResponse;

final class LoginController
{
    public function __invoke(LoginFormRequest $request, LoginUseCase $login): JsonResponse
    {
        $response = $login->execute(new LoginRequest(
            $request->string('email')->toString(), $request->string('password')->toString(),
        ));

        return response()->json(['data' => AuthPresenter::login($response)]);
    }
}
