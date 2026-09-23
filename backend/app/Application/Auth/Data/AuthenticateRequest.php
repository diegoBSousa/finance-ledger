<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

use SensitiveParameter;

final readonly class AuthenticateRequest
{
    public function __construct(#[SensitiveParameter] public string $token) {}
}
