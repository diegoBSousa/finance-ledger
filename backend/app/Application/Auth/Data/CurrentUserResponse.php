<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

final readonly class CurrentUserResponse
{
    public function __construct(public UserData $user) {}
}
