<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Application\Transactions\Data\TransactionData;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $owner_user_id
 * @property string $account_number
 * @property string $posting_date
 * @property string $description
 * @property string $movement_type
 * @property string $amount_minor
 * @property string $currency
 * @property CarbonImmutable $created_at
 */
final class JournalEntryRecord extends Model
{
    protected $table = 'journal_entries';

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['id' => 'string', 'owner_user_id' => 'string', 'account_number' => 'string', 'amount_minor' => 'string', 'created_at' => 'immutable_datetime'];
    }

    public function toData(): TransactionData
    {
        return new TransactionData($this->id, $this->owner_user_id, $this->account_number, $this->posting_date,
            $this->description, $this->movement_type, Money::positiveFromDecimal($this->amount_minor)->toDecimal(),
            $this->currency, $this->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'));
    }
}
