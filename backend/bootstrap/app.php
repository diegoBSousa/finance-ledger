<?php

use App\Application\Auth\AuthenticationFailed;
use App\Application\Auth\AuthenticationUnavailable;
use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['jwt.auth' => AuthenticateJwt::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontReport([AuthenticationFailed::class]);
        $exceptions->render(fn (AuthenticationFailed $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => $exception->reason,
        ], 401, ['WWW-Authenticate' => 'Bearer']));
        $exceptions->render(fn (AuthenticationUnavailable $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => 'authentication_unavailable',
        ], 503));
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('api/v1/auth/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');
            }

            return $response;
        });
    })->create();
