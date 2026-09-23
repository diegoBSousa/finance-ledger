<?php

use App\Application\Auth\AuthenticationFailed;
use App\Application\Auth\AuthenticationUnavailable;
use App\Application\Balances\AccountNotFound;
use App\Application\Balances\BalanceUnavailable;
use App\Application\Imports\ImportNotFound;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;
use App\Application\Imports\UploadTooLarge;
use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
        $exceptions->dontReport([AuthenticationFailed::class, AccountNotFound::class]);
        $exceptions->dontReport([InvalidImportFile::class, UploadTooLarge::class, ImportNotFound::class]);
        $exceptions->render(fn (InvalidImportFile $e) => response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 422));
        $exceptions->render(fn (ImportNotFound $e) => response()->json(['message' => $e->getMessage(), 'code' => 'import_not_found'], 404));
        $exceptions->render(fn (ImportUnavailable $e) => response()->json(['message' => $e->getMessage(), 'code' => 'import_unavailable'], 503));
        $tooLarge = fn () => response()->json(['message' => 'The CSV must not exceed 100000000 bytes.', 'code' => 'upload_too_large'], 413);
        $exceptions->render(fn (UploadTooLarge $e) => $tooLarge());
        $exceptions->render(fn (PostTooLargeException $e) => $tooLarge());

        $exceptions->render(fn (AccountNotFound $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => 'account_not_found',
        ], 404));
        $exceptions->render(fn (BalanceUnavailable $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => 'balance_unavailable',
        ], 503, ['Retry-After' => '1']));
        $exceptions->render(fn (AuthenticationFailed $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => $exception->reason,
        ], 401, ['WWW-Authenticate' => 'Bearer']));
        $exceptions->render(fn (AuthenticationUnavailable $exception) => response()->json([
            'message' => $exception->getMessage(), 'code' => 'authentication_unavailable',
        ], 503));
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('api/v1/*') && ! request()->is('api/v1/health')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');
            }

            return $response;
        });
    })->create();
