<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

final readonly class CurrentUserRequest
{
    // Created from trusted middleware context, never from request parameters.
    public function __construct(public AuthenticationData $authentication) {}
}
