<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

final readonly class LoginResponse
{
    public function __construct(public UserData $user, public IssuedTokenData $token) {}
}
