<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

final readonly class UserData
{
    public function __construct(public string $id, public string $name, public string $email) {}
}
