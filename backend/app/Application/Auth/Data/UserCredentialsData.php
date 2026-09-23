<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

use SensitiveParameter;

final readonly class UserCredentialsData
{
    public function __construct(public UserData $user, #[SensitiveParameter] public string $passwordHash) {}
}
