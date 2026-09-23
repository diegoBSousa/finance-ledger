<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Imports\Contracts\ImportAccountRepository;
use App\Application\Imports\Data\ImportAccountsData;
use App\Application\Imports\ImportUnavailable;
use App\Infrastructure\Persistence\Models\AccountRecord;
use PDOException;

final class MysqlImportAccountRepository implements ImportAccountRepository
{
    public function resolve(string $actorUserId, array $externalNumbers): ImportAccountsData
    {
        try {
            $accounts = AccountRecord::query()->where(fn ($query) => $query->where('kind', 'asset')->whereIn('external_number', $externalNumbers))
                ->orWhere(fn ($query) => $query->where('owner_user_id', $actorUserId)->whereIn('kind', ['revenue', 'expense']))
                ->get()->map(fn (AccountRecord $row) => $row->toData())->all();

            return new ImportAccountsData($accounts);
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }
}
