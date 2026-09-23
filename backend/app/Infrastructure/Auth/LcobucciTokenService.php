<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Application\Auth\AuthenticationFailed;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Data\IssuedTokenData;
use App\Application\Auth\Data\TokenClaimsData;
use App\Application\Shared\Contracts\Clock;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use JsonException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use SensitiveParameter;
use TypeError;
use ValueError;

final readonly class LcobucciTokenService implements TokenService
{
    private Configuration $configuration;

    private JwtClock $clock;

    public function __construct(Clock $clock, private JwtSettings $settings)
    {
        $this->clock = new JwtClock($clock);
        $configuration = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText($settings->key));
        $this->configuration = $configuration->withValidationConstraints(
            new SignedWith($configuration->signer(), $configuration->verificationKey()),
            new IssuedBy($settings->issuer),
            new PermittedFor($settings->audience),
            new StrictValidAt($this->clock),
        );
    }

    public function issue(string $userId): IssuedTokenData
    {
        $userId = (string) DecimalInteger::positive($userId);
        $now = $this->clock->now();
        $token = $this->configuration->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('typ', 'at+jwt')
            ->issuedBy($this->settings->issuer)
            ->permittedFor($this->settings->audience)
            ->relatedTo($userId)
            ->identifiedBy(bin2hex(random_bytes(32)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+'.JwtSettings::TTL_SECONDS.' seconds'))
            ->getToken($this->configuration->signer(), $this->configuration->signingKey());

        return new IssuedTokenData($token->toString(), JwtSettings::TTL_SECONDS);
    }

    public function validate(#[SensitiveParameter] string $token): TokenClaimsData
    {
        if (strlen($token) > 4096 || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $token) !== 1) {
            throw AuthenticationFailed::token();
        }

        try {
            $parsed = $this->configuration->parser()->parse($token);
            if (! $parsed instanceof UnencryptedToken
                || $parsed->headers()->get('typ') !== 'at+jwt'
                || array_diff(array_keys($parsed->headers()->all()), ['typ', 'alg']) !== []
                || ! $this->configuration->validator()->validate($parsed, ...$this->configuration->validationConstraints())) {
                throw AuthenticationFailed::token();
            }

            // The library converts numeric dates; additionally enforce our original JSON claim types.
            $payload = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), strict: true);
            $claims = $payload === false ? null : json_decode($payload, associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
            if (! is_array($claims)
                || ! is_string($claims['sub'] ?? null)
                || ! is_string($claims['jti'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $claims['jti']) !== 1
                || ! is_int($claims['iat'] ?? null)
                || ! is_int($claims['nbf'] ?? null)
                || ! is_int($claims['exp'] ?? null)
                || $claims['iat'] <= 0 || $claims['nbf'] !== $claims['iat']
                || $claims['exp'] <= $claims['iat']
                || $claims['exp'] - $claims['iat'] > JwtSettings::TTL_SECONDS
                || (string) DecimalInteger::positive($claims['sub']) !== $claims['sub']) {
                throw AuthenticationFailed::token();
            }

            return new TokenClaimsData($claims['sub'], $claims['jti'], $claims['iat'], $claims['exp']);
        } catch (JwtException|JsonException|TypeError|ValueError|DomainViolation) {
            throw AuthenticationFailed::token();
        }
    }
}
