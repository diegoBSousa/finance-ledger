<?php

declare(strict_types=1);

namespace App\Application\Auth\Contracts;

use App\Application\Auth\Data\IssuedTokenData;
use App\Application\Auth\Data\TokenClaimsData;

interface TokenService
{
    public function issue(string $userId): IssuedTokenData;

    /** Verify signature, profile and all required claims; never merely decode. */
    public function validate(string $token): TokenClaimsData;
}
