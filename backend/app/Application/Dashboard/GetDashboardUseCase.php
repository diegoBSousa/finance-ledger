<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Contracts\DashboardRepository;
use App\Application\Dashboard\Data\GetDashboardRequest;
use App\Application\Dashboard\Data\GetDashboardResponse;
use App\Application\Shared\ReadUnavailable;

final readonly class GetDashboardUseCase
{
    public function __construct(private DashboardRepository $repository, private DashboardCache $cache) {}

    public function execute(GetDashboardRequest $request): GetDashboardResponse
    {
        // SQL must succeed even on a cache hit. TTL never decides financial freshness.
        $revision = $this->repository->revision($request->actorUserId);
        try {
            $cached = $this->cache->get($request->actorUserId, $revision);
            if ($cached !== null && $cached->ownerUserId === $request->actorUserId && $cached->revision === $revision) {
                return new GetDashboardResponse($cached);
            }
        } catch (CacheUnavailable) {
            // Cache is optional; use the committed source below.
        }
        $data = $this->repository->snapshot($request->actorUserId);
        if ($data->ownerUserId !== $request->actorUserId || FinancialRevision::compare($data->revision, $revision) < 0) {
            throw new ReadUnavailable;
        }
        try {
            // The snapshot may have advanced since the first read. Store under its own revision.
            $this->cache->put($data);
        } catch (CacheUnavailable) {
            // A failed cache write must not discard a valid SQL response.
        }

        return new GetDashboardResponse($data);
    }
}
