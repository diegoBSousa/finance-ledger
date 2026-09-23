<?php

declare(strict_types=1);

namespace App\Application\Balances;

use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\RefreshAccountBalanceRequest;
use App\Application\Balances\Data\RefreshAccountBalanceResponse;
use App\Application\Balances\Data\RefreshBalanceData;

final readonly class RefreshAccountBalanceUseCase
{
    public function __construct(private BalanceRepository $balances) {}

    public function execute(RefreshAccountBalanceRequest $request): RefreshAccountBalanceResponse
    {
        $query = new RefreshBalanceData($request->ownerUserId, $request->accountId, $request->skipLocked);
        $result = $this->balances->refresh($query);
        if ($result === null) {
            if (! $query->skipLocked) {
                throw new BalanceUnavailable;
            }

            return new RefreshAccountBalanceResponse(null, false);
        }
        $balance = $result->balance;
        if ($balance->staled || $balance->ledgerVersion !== $balance->calculatedVersion
            || $balance->account->id !== $query->accountId || $balance->account->ownerUserId !== $query->ownerUserId) {
            throw new BalanceUnavailable;
        }

        return new RefreshAccountBalanceResponse($balance, $result->recalculated);
    }
}
