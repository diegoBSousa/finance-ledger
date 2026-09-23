<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\AccountKind;
use App\Infrastructure\Persistence\AccountProvisioner;
use App\Infrastructure\Persistence\Models\AccountRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class DemoAccountsSeeder extends Seeder
{
    public function run(AccountProvisioner $provisioner): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo accounts may only be seeded in local or testing environments.');
        }

        $email = config('demo.email');
        $password = config('demo.password');
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('DEMO_USER_EMAIL must be a valid email address.');
        }
        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('Set DEMO_USER_PASSWORD to at least 16 characters before seeding.');
        }

        DB::transaction(function () use ($provisioner, $email, $password): void {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => 'Demo Ledger',
                    'email' => $email,
                    'password' => Hash::driver('argon2id')->make($password),
                ])->saveOrFail();
            }

            $ownerId = (string) $user->id;
            $this->assertExistingProjections($ownerId);
            for ($number = 100; $number <= 999; $number++) {
                $account = AccountRecord::query()->where('external_number', $number)->first();
                if ($account === null) {
                    $provisioner->create($ownerId, AccountKind::Asset, (string) $number);
                } elseif ($account->owner_user_id !== $ownerId || $account->kind !== AccountKind::Asset->value) {
                    throw new RuntimeException('Demo account #'.$number.' already belongs to another owner or kind.');
                }
            }

            foreach ([AccountKind::Revenue, AccountKind::Expense] as $kind) {
                if (! AccountRecord::query()->where('owner_user_id', $ownerId)->where('kind', $kind->value)->exists()) {
                    $provisioner->create($ownerId, $kind);
                }
            }
        });
    }

    private function assertExistingProjections(string $ownerId): void
    {
        // Existing ledger data must never be silently initialized with zero balances/revision.
        if (! DB::table('accounts')->where('owner_user_id', $ownerId)->exists()) {
            return;
        }

        $missingBalance = DB::table('accounts')->where('owner_user_id', $ownerId)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('account_balances')
                ->whereColumn('account_balances.account_id', 'accounts.id'))
            ->exists();
        if ($missingBalance || ! DB::table('financial_states')->where('owner_user_id', $ownerId)->exists()) {
            throw new RuntimeException('Existing demo accounts are missing financial state or projections; reconcile them explicitly.');
        }
    }
}
