<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\DomainViolation;
use App\Infrastructure\Persistence\Models\AccountRecord;
use Illuminate\Support\Facades\DB;

/** Infrastructure for provisioning accounts with their initial projection, currently used by seeds. */
final class AccountProvisioner
{
    public function create(string $ownerUserId, AccountKind $kind, ?string $externalNumber = null): AccountData
    {
        return DB::transaction(function () use ($ownerUserId, $kind, $externalNumber): AccountData {
            // Serialize provisioning for the same owner, including creation of its financial state.
            $owner = DB::table('users')->where('id', $ownerUserId)->lockForUpdate()->first(['id']);
            if ($owner === null) {
                throw new DomainViolation('owner_not_found', 'The account owner must exist before provisioning.');
            }

            if (! DB::table('financial_states')->where('owner_user_id', $ownerUserId)->exists()) {
                DB::table('financial_states')->insert(['owner_user_id' => $ownerUserId, 'revision' => 0]);
            }

            $record = new AccountRecord;
            $record->forceFill([
                'owner_user_id' => $ownerUserId,
                'external_number' => $externalNumber,
                'kind' => $kind->value,
                'currency' => 'BRL',
                'active' => true,
            ])->saveOrFail();

            $account = Account::fromData($record->toData());
            DB::table('account_balances')->insert([
                'account_id' => $account->id,
                'calculated_at' => now(),
            ]);

            return $account->toData();
        });
    }
}
