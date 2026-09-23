<?php

declare(strict_types=1);

namespace App\Http\Controllers\Imports;

use App\Application\Imports\Data\GetImportRequest;
use App\Application\Imports\GetImportUseCase;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\ImportPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GetImportController
{
    public function __invoke(Request $request, string $importId, GetImportUseCase $get): JsonResponse
    {
        $response = $get->execute(new GetImportRequest(AuthenticatedContext::fromRequest($request)->user->id, $importId));

        return response()->json(['data' => ImportPresenter::import($response->import)]);
    }
}
