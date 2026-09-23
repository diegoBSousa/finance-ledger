<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

enum EntrySide: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
