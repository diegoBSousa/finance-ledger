<?php

declare(strict_types=1);

namespace App\Http\Controllers\Imports;

use App\Application\Imports\Data\ListImportsRequest;
use App\Application\Imports\ListImportsUseCase;
use App\Application\Pagination\PageRequest;
use App\Http\AuthenticatedContext;
use App\Http\Presenters\ImportPresenter;
use App\Http\Requests\PaginationFormRequest;
use Illuminate\Http\JsonResponse;

final class ListImportsController
{
    public function __invoke(PaginationFormRequest $request, ListImportsUseCase $list): JsonResponse
    {
        $input = $request->validated();
        $response = $list->execute(new ListImportsRequest(AuthenticatedContext::fromRequest($request)->user->id,
            new PageRequest((int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10))));

        return response()->json(ImportPresenter::page($response));
    }
}
