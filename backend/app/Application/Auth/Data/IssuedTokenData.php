<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

use SensitiveParameter;

final readonly class IssuedTokenData
{
    public function __construct(#[SensitiveParameter] public string $accessToken, public int $expiresIn) {}
}
