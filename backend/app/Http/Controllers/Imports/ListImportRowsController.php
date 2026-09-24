<?php

declare(strict_types=1);

namespace App\Http\Controllers\Imports;

use App\Application\Imports\Data\ImportRowsQueryData;
use App\Application\Imports\Data\ListImportRowsRequest;
use App\Application\Imports\ListImportRowsUseCase;
use App\Application\Pagination\PageRequest;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\ImportRowPresenter;
use App\Http\Requests\ImportRowsFormRequest;
use Illuminate\Http\JsonResponse;

final class ListImportRowsController
{
    public function __invoke(ImportRowsFormRequest $request, ListImportRowsUseCase $list): JsonResponse
    {
        $input = $request->validated();
        $response = $list->execute(new ListImportRowsRequest(new ImportRowsQueryData(AuthenticatedContext::fromRequest($request)->user->id,
            $input['import_id'], new PageRequest((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10)), $input['status'] ?? null)));

        return response()->json(ImportRowPresenter::page($response));
    }
}
