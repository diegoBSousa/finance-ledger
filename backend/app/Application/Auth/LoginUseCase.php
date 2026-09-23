<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Contracts\PasswordHasher;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\LoginRequest;
use App\Application\Auth\Data\LoginResponse;

final readonly class LoginUseCase
{
    public function __construct(private UserRepository $users, private PasswordHasher $passwords, private TokenService $tokens) {}

    public function execute(LoginRequest $request): LoginResponse
    {
        $credentials = $this->users->findCredentialsByEmail($request->email);
        $valid = $this->passwords->verify($request->password, $credentials?->passwordHash);
        if (! $valid || $credentials === null || strtolower($credentials->user->email) !== $request->email) {
            throw AuthenticationFailed::credentials();
        }

        if ($this->passwords->needsRehash($credentials->passwordHash)) {
            $this->users->replacePasswordHash(
                $credentials->user->id,
                $credentials->passwordHash,
                $this->passwords->hash($request->password),
            );
        }

        return new LoginResponse($credentials->user, $this->tokens->issue($credentials->user->id));
    }
}
