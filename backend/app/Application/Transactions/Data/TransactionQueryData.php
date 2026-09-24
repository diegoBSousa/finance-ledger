<?php

declare(strict_types=1);

namespace App\Application\Transactions\Data;

use App\Application\Pagination\PageRequest;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;

final readonly class TransactionQueryData
{
    public function __construct(public string $actorUserId, public PageRequest $pagination, public ?string $accountNumber = null, public ?string $dateFrom = null, public ?string $dateTo = null, public ?string $type = null)
    {
        DecimalInteger::positive($actorUserId);
        if ($accountNumber !== null && (string) DecimalInteger::positive($accountNumber) !== $accountNumber) {
            throw new DomainViolation('invalid_account', 'Expected a canonical account number.');
        }
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date !== null && (preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $date) !== 1
                || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                throw new DomainViolation('invalid_date', 'Expected an existing ISO date.');
            }
        }
        if (($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) || ($type !== null && ! in_array($type, ['income', 'expense'], true))) {
            throw new DomainViolation('invalid_filters', 'Invalid transaction filters.');
        }
    }
}
