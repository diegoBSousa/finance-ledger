<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;

enum AccountKind: string
{
    case Asset = 'asset';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function normalSide(): EntrySide
    {
        return $this === self::Revenue ? EntrySide::Credit : EntrySide::Debit;
    }

    public function balance(Money $debits, Money $credits): Money
    {
        if ($debits->minor < 0 || $credits->minor < 0) {
            throw new DomainViolation('invalid_totals', 'Debit and credit totals cannot be negative.');
        }

        return $this->normalSide() === EntrySide::Debit
            ? $debits->subtract($credits)
            : $credits->subtract($debits);
    }
}
