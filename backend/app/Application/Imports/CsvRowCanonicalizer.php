<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Imports\Data\CanonicalRowData;
use App\Application\Imports\Data\CsvRowData;
use App\Domain\Accounting\AccountNumber;
use App\Domain\Accounting\MovementType;
use App\Domain\Accounting\PostingDate;
use App\Domain\Shared\Currency;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Utf8Text;

final class CsvRowCanonicalizer
{
    public const string VERSION = 'csv-row-v1';

    public function canonicalize(CsvRowData $row): CanonicalRowData
    {
        $date = new PostingDate(Utf8Text::canonical($row->date));
        $amount = Money::positiveFromDecimal(Utf8Text::canonical($row->amount));
        $type = MovementType::fromCsv($row->type);
        $descriptionWithAccount = Utf8Text::canonical($row->description);

        if (preg_match('/\A(.*)#([0-9]+)\z/us', $descriptionWithAccount, $parts) !== 1) {
            throw new DomainViolation('missing_account_suffix', 'The description must end with # followed by the account number.');
        }

        $description = Utf8Text::canonical($parts[1]);
        $account = new AccountNumber($parts[2]);

        if ($description === '') {
            throw new DomainViolation('empty_description', 'The description before the account suffix cannot be empty.');
        }

        // Fixed order and escaping are part of the persisted identity contract.
        $canonical = json_encode([
            self::VERSION,
            Currency::BRL->value,
            $date->value,
            $description,
            $amount->toDecimal(),
            $type->value,
            $account->value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new CanonicalRowData(
            $date->value,
            $description,
            $amount->toDecimal(),
            $type->value,
            $account->value,
            $canonical,
            hash('sha256', $canonical),
        );
    }
}
