<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Application\Balances\Data\AccountBalanceData;
use App\Domain\Accounting\Data\AccountData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $account_id
 * @property string $debit_total_minor
 * @property string $credit_total_minor
 * @property string $balance_minor
 * @property string $ledger_version
 * @property string $calculated_version
 * @property bool $staled
 * @property Carbon|null $calculated_at
 */
final class AccountBalanceRecord extends Model
{
    protected $table = 'account_balances';

    protected $primaryKey = 'account_id';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_id' => 'string', 'debit_total_minor' => 'string', 'credit_total_minor' => 'string',
            'balance_minor' => 'string', 'ledger_version' => 'string', 'calculated_version' => 'string',
            'staled' => 'boolean', 'calculated_at' => 'datetime',
        ];
    }

    public function toData(AccountData $account): AccountBalanceData
    {
        return new AccountBalanceData($account, $this->debit_total_minor, $this->credit_total_minor,
            $this->balance_minor, $this->ledger_version, $this->calculated_version,
            $this->calculated_at?->utc()->format('Y-m-d\TH:i:s.u\Z'), $this->staled);
    }
}
