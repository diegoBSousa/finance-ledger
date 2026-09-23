<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Application\Auth\AuthenticationUnavailable;
use SensitiveParameter;

final readonly class JwtSettings
{
    public const int TTL_SECONDS = 900;

    public string $key;

    public function __construct(
        #[SensitiveParameter] string $secretBase64,
        public string $issuer,
        public string $audience,
    ) {
        $key = base64_decode($secretBase64, strict: true);
        if ($key === false || strlen($key) < 32 || $issuer === '' || $audience === '') {
            throw new AuthenticationUnavailable;
        }
        $this->key = $key;
    }
}
