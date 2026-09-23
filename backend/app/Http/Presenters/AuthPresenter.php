<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Auth\Data\LoginResponse;
use App\Application\Auth\Data\UserData;

final class AuthPresenter
{
    /** @return array{id: string, name: string, email: string} */
    public static function user(UserData $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    /** @return array<string, mixed> */
    public static function login(LoginResponse $response): array
    {
        return [
            'access_token' => $response->token->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $response->token->expiresIn,
            'user' => self::user($response->user),
        ];
    }
}
