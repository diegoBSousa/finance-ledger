<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Data\LogoutRequest;
use App\Application\Auth\Data\LogoutResponse;

final readonly class LogoutUseCase
{
    public function __construct(private TokenRevocationRepository $revocations) {}

    public function execute(LogoutRequest $request): LogoutResponse
    {
        $this->revocations->revoke($request->authentication->token);

        return new LogoutResponse(revoked: true);
    }
}
