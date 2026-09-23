<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Application\Auth\Contracts\PasswordHasher;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

final readonly class Argon2idPasswordHasher implements PasswordHasher
{
    // Verification cost for unknown users matches the configured default (64 MiB, t=3, p=1).
    private const string DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$cVVtU1lUT2dYT2toLkc4aA$4tuiR6PdLKqdJNkFxzPBdq09d1x2zcUPISg2VaDpzio';

    public function __construct(private Hasher $hasher) {}

    public function hash(#[SensitiveParameter] string $password): string
    {
        return $this->hasher->make($password);
    }

    public function verify(#[SensitiveParameter] string $password, #[SensitiveParameter] ?string $hash): bool
    {
        $supported = $hash !== null && password_get_info($hash)['algoName'] === 'argon2id';
        $valid = $this->hasher->check($password, $supported ? $hash : self::DUMMY_HASH);

        return $supported && $valid;
    }

    public function needsRehash(#[SensitiveParameter] string $hash): bool
    {
        return $this->hasher->needsRehash($hash);
    }
}
