<?php

declare(strict_types=1);

namespace App\Application\Auth;

use RuntimeException;

final class AuthenticationUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Authentication is temporarily unavailable.');
    }
}
