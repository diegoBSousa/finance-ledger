<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use App\Domain\Accounting\Data\AccountData;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $owner_user_id
 * @property string|null $external_number
 * @property string $kind
 * @property string $currency
 * @property bool $active
 */
final class AccountRecord extends Model
{
    protected $table = 'accounts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'string',
            'owner_user_id' => 'string',
            'external_number' => 'string',
            'active' => 'boolean',
        ];
    }

    public function toData(): AccountData
    {
        return new AccountData(
            id: $this->id,
            ownerUserId: $this->owner_user_id,
            externalNumber: $this->external_number,
            kind: $this->kind,
            currency: $this->currency,
            active: $this->active,
        );
    }
}
