<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Data\CurrentUserRequest;
use App\Application\Auth\Data\CurrentUserResponse;

final readonly class CurrentUserUseCase
{
    public function execute(CurrentUserRequest $request): CurrentUserResponse
    {
        return new CurrentUserResponse($request->authentication->user);
    }
}
