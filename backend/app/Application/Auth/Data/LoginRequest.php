<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

use App\Domain\Shared\DomainViolation;
use SensitiveParameter;

final readonly class LoginRequest
{
    public string $email;

    public function __construct(string $email, #[SensitiveParameter] public string $password)
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $password === '' || strlen($password) > 1024 || str_contains($password, "\0")) {
            throw new DomainViolation('invalid_login_input', 'A valid email and a password of 1 to 1024 bytes are required.');
        }
        $this->email = $email;
    }
}
