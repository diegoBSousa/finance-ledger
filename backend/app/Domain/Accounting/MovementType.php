<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Utf8Text;

enum MovementType: string
{
    case Income = 'income';
    case Expense = 'expense';

    public static function fromCsv(string $value): self
    {
        return match (strtolower(Utf8Text::canonical($value))) {
            'receita' => self::Income,
            'despesa' => self::Expense,
            default => throw new DomainViolation('invalid_movement_type', 'Expected Receita or Despesa.'),
        };
    }

    public function counterpartKind(): AccountKind
    {
        return $this === self::Income ? AccountKind::Revenue : AccountKind::Expense;
    }
}
