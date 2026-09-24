<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Contracts\DashboardInvalidationRepository;
use App\Application\Dashboard\Data\InvalidateDashboardRequest;
use App\Application\Dashboard\Data\InvalidateDashboardResponse;

final readonly class InvalidateDashboardUseCase
{
    public function __construct(private DashboardInvalidationRepository $repository, private DashboardCache $cache) {}

    public function execute(InvalidateDashboardRequest $request): InvalidateDashboardResponse
    {
        $event = $this->repository->pending($request->deliveryId);
        if ($event === null) {
            return new InvalidateDashboardResponse(false);
        }
        $this->cache->invalidate($event->ownerUserId, $event->revision);
        $this->repository->acknowledge($event);

        return new InvalidateDashboardResponse(true);
    }
}
