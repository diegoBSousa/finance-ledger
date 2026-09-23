<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\AuthenticateRequest;
use App\Application\Auth\Data\AuthenticationData;

final readonly class AuthenticateTokenUseCase
{
    public function __construct(private TokenService $tokens, private TokenRevocationRepository $revocations, private UserRepository $users) {}

    public function execute(AuthenticateRequest $request): AuthenticationData
    {
        $claims = $this->tokens->validate($request->token);
        if ($this->revocations->isRevoked($claims->tokenId)) {
            throw AuthenticationFailed::token();
        }

        $user = $this->users->findById($claims->userId);
        if ($user === null || $user->id !== $claims->userId) {
            throw AuthenticationFailed::token();
        }

        return new AuthenticationData($user, $claims);
    }
}
