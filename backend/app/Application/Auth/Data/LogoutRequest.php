<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

final readonly class LogoutRequest
{
    // Only the authenticated token can be revoked by this endpoint.
    public function __construct(public AuthenticationData $authentication) {}
}
