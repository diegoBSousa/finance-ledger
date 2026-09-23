<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

final readonly class LogoutResponse
{
    public function __construct(public bool $revoked) {}
}
