<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Auth\AuthenticateTokenUseCase;
use App\Application\Auth\AuthenticationFailed;
use App\Application\Auth\Data\AuthenticateRequest;
use App\Http\AuthenticatedContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateJwt
{
    public function __construct(private AuthenticateTokenUseCase $authenticate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $headers = $request->headers->all('authorization');
        if (count($headers) !== 1 || ! is_string($headers[0])
            || preg_match('/\ABearer[ \t]+([^ \t\r\n,]+)\z/i', $headers[0], $matches) !== 1) {
            throw AuthenticationFailed::token();
        }

        $authentication = $this->authenticate->execute(new AuthenticateRequest($matches[1]));
        $request->attributes->set(AuthenticatedContext::ATTRIBUTE, $authentication);

        return $next($request);
    }
}
