<?php

declare(strict_types=1);

namespace App\Application\Auth;

use RuntimeException;

final class AuthenticationFailed extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function credentials(): self
    {
        return new self('invalid_credentials', 'Invalid email or password.');
    }

    public static function token(): self
    {
        return new self('unauthenticated', 'Unauthenticated.');
    }
}
