<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Auth\AuthenticationFailed;
use App\Application\Auth\Data\AuthenticationData;
use Illuminate\Http\Request;

final class AuthenticatedContext
{
    public const string ATTRIBUTE = 'finance.authenticated';

    public static function fromRequest(Request $request): AuthenticationData
    {
        $authentication = $request->attributes->get(self::ATTRIBUTE);
        if (! $authentication instanceof AuthenticationData) {
            throw AuthenticationFailed::token();
        }

        return $authentication;
    }
}
