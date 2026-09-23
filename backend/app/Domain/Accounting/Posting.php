<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Accounting\Data\PostingData;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;

final readonly class Posting
{
    public function __construct(public Account $account, public EntrySide $side, public Money $amount)
    {
        if ($amount->minor <= 0) {
            throw new DomainViolation('non_positive_posting', 'Posting amounts must be positive.');
        }

        if (! $account->active) {
            throw new DomainViolation('inactive_account', 'Inactive accounts cannot receive new postings.');
        }
    }

    public function toData(): PostingData
    {
        return new PostingData($this->account->id, $this->side->value, $this->amount->toDecimal(), $this->amount->currency->value);
    }
}
