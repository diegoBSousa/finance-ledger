<?php

declare(strict_types=1);

namespace App\Application\Balances;

use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\ProjectBalancesRequest;
use App\Application\Balances\Data\ProjectBalancesResponse;
use App\Application\Balances\Data\RefreshAccountBalanceRequest;

final readonly class ProjectBalancesUseCase
{
    public function __construct(private BalanceRepository $balances, private RefreshAccountBalanceUseCase $refresh) {}

    public function execute(ProjectBalancesRequest $request): ProjectBalancesResponse
    {
        $pending = $this->balances->pending($request->afterAccountId);
        $recalculated = $skipped = $failed = 0;
        $cursor = '0';
        foreach ($pending->accounts as $account) {
            $cursor = $account->id; // Advance past failed/locked rows so they cannot starve later accounts.
            try {
                $result = $this->refresh->execute(new RefreshAccountBalanceRequest($account->ownerUserId, $account->id, true));
                $recalculated += (int) $result->recalculated;
                $skipped += (int) ! $result->recalculated;
            } catch (BalanceUnavailable|AccountNotFound) {
                $failed++;
            }
        }

        return new ProjectBalancesResponse($cursor, count($pending->accounts), $recalculated, $skipped, $failed, $pending->pendingCount, $pending->oldestStaledAt);
    }
}
