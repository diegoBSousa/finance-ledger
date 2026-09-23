<?php

declare(strict_types=1);

namespace App\Http\Controllers\Imports;

use App\Application\Imports\Data\UploadCsvRequest;
use App\Application\Imports\UploadCsvUseCase;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\ImportPresenter;
use App\Http\Requests\UploadCsvFormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class UploadCsvController
{
    public function __invoke(UploadCsvFormRequest $request, UploadCsvUseCase $upload): JsonResponse
    {
        /** @var UploadedFile $file */ $file = $request->file('file');
        $response = $upload->execute(new UploadCsvRequest(AuthenticatedContext::fromRequest($request)->user->id, $file->getPathname(), $file->getClientOriginalName()));

        return response()->json(['data' => ImportPresenter::import($response->import)], 202, ['Location' => '/api/v1/imports/'.$response->import->id]);
    }
}
