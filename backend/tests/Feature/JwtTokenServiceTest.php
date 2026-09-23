<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Auth\AuthenticationFailed;
use App\Application\Auth\AuthenticationUnavailable;
use App\Infrastructure\Auth\JwtSettings;
use App\Infrastructure\Auth\LcobucciTokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\FrozenClock;

final class JwtTokenServiceTest extends TestCase
{
    private FrozenClock $clock;

    private LcobucciTokenService $tokens;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
        $this->tokens = new LcobucciTokenService($this->clock, new JwtSettings(base64_encode(str_repeat('k', 32)), 'issuer', 'audience'));
    }

    public function test_issued_tokens_have_minimal_claims_fifteen_minute_lifetime_and_unique_ids(): void
    {
        $first = $this->tokens->issue('9007199254740993');
        $second = $this->tokens->issue('9007199254740993');
        $claims = $this->tokens->validate($first->accessToken);
        self::assertSame('9007199254740993', $claims->userId);
        self::assertSame(900, $first->expiresIn);
        self::assertSame($this->clock->now() + 900, $claims->expiresAt);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $claims->tokenId);
        self::assertNotSame($claims->tokenId, $this->tokens->validate($second->accessToken)->tokenId);
        $payload = json_decode(base64_decode(strtr(explode('.', $first->accessToken)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(['iss', 'aud', 'sub', 'jti', 'iat', 'nbf', 'exp'], array_keys($payload));
    }

    public function test_expiration_is_rejected_at_the_exact_boundary(): void
    {
        $token = $this->tokens->issue('7')->accessToken;
        $this->clock->timestamp += 899;
        self::assertSame('7', $this->tokens->validate($token)->userId);
        $this->clock->timestamp++;
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->validate($token);
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_are_rejected_even_with_a_valid_signature(string $field, mixed $value): void
    {
        $claims = $this->claims();
        if ($value === null) {
            unset($claims[$field]);
        } else {
            $claims[$field] = $value;
        }
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->validate($this->signed($claims));
    }

    public static function invalidClaims(): iterable
    {
        yield 'missing issuer' => ['iss', null];
        yield 'wrong issuer' => ['iss', 'other-issuer'];
        yield 'wrong audience' => ['aud', 'other-audience'];
        yield 'missing audience' => ['aud', null];
        yield 'missing subject' => ['sub', null];
        yield 'numeric subject' => ['sub', 7];
        yield 'zero subject' => ['sub', '0'];
        yield 'noncanonical subject' => ['sub', '007'];
        yield 'subject overflow' => ['sub', '9223372036854775808'];
        yield 'missing jti' => ['jti', null];
        yield 'short jti' => ['jti', 'known-id'];
        yield 'array jti' => ['jti', ['invalid']];
        yield 'missing iat' => ['iat', null];
        yield 'future iat' => ['iat', 1790000001];
        yield 'string iat' => ['iat', '1790000000'];
        yield 'array iat' => ['iat', []];
        yield 'missing nbf' => ['nbf', null];
        yield 'future nbf' => ['nbf', 1790000001];
        yield 'nbf before issuance' => ['nbf', 1789999999];
        yield 'missing expiration' => ['exp', null];
        yield 'expired' => ['exp', 1790000000];
        yield 'expiration exceeds profile lifetime' => ['exp', 1790000901];
        yield 'string expiration' => ['exp', '1790000900'];
        yield 'fractional expiration' => ['exp', 1790000899.5];
    }

    #[DataProvider('invalidHeaders')]
    public function test_unexpected_algorithm_type_or_headers_are_rejected(array $headers): void
    {
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->validate($this->signed($this->claims(), $headers));
    }

    public static function invalidHeaders(): iterable
    {
        yield 'none algorithm' => [['alg' => 'none', 'typ' => 'at+jwt']];
        yield 'different algorithm' => [['alg' => 'HS512', 'typ' => 'at+jwt']];
        yield 'missing algorithm' => [['typ' => 'at+jwt']];
        yield 'wrong type' => [['alg' => 'HS256', 'typ' => 'JWT']];
        yield 'missing type' => [['alg' => 'HS256']];
        yield 'unsupported critical header' => [['alg' => 'HS256', 'typ' => 'at+jwt', 'crit' => ['custom']]];
        yield 'remote key header' => [['alg' => 'HS256', 'typ' => 'at+jwt', 'jku' => 'https://untrusted.example/keys']];
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->validate($this->signed($this->claims(), key: str_repeat('x', 32)));
    }

    public function test_payload_cannot_be_tampered_with(): void
    {
        $parts = explode('.', $this->tokens->issue('7')->accessToken);
        $claims = $this->claims();
        $claims['sub'] = '8';
        $parts[1] = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->validate(implode('.', $parts));
    }

    #[DataProvider('malformedTokens')]
    public function test_malformed_tokens_are_authentication_failures(string $token): void
    {
        $this->expectException(AuthenticationFailed::class);
        $this->tokens->validate($token);
    }

    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'not jwt' => ['not-a-token'];
        yield 'invalid base64 and json' => ['a.a.a'];
        yield 'empty signature' => ['eyJhbGciOiJub25lIn0.e30.'];
        yield 'too many segments' => ['a.b.c.d'];
        yield 'too large' => [str_repeat('a', 4097)];
    }

    #[DataProvider('badKeys')]
    public function test_missing_or_weak_server_key_fails_closed(string $key): void
    {
        $this->expectException(AuthenticationUnavailable::class);
        new JwtSettings($key, 'issuer', 'audience');
    }

    public static function badKeys(): iterable
    {
        yield 'missing key' => [''];
        yield 'short key' => [base64_encode('short')];
        yield 'invalid base64' => ['not%base64'];
    }

    private function claims(): array
    {
        return ['iss' => 'issuer', 'aud' => 'audience', 'sub' => '7', 'jti' => str_repeat('a', 64),
            'iat' => 1790000000, 'nbf' => 1790000000, 'exp' => 1790000900];
    }

    private function signed(array $claims, array $headers = ['alg' => 'HS256', 'typ' => 'at+jwt'], ?string $key = null): string
    {
        $body = $this->encode(json_encode($headers, JSON_THROW_ON_ERROR)).'.'.$this->encode(json_encode($claims, JSON_THROW_ON_ERROR));

        return $body.'.'.$this->encode(hash_hmac('sha256', $body, $key ?? str_repeat('k', 32), true));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
