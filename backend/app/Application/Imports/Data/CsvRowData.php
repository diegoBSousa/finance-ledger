<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Domain\Shared\DomainViolation;

final readonly class CsvRowData
{
    public function __construct(
        public string $date,
        public string $description,
        public string $amount,
        public string $type,
    ) {}

    /** @param array<array-key, string|null> $fields Fields already decoded by a CSV parser. */
    public static function fromFields(array $fields): self
    {
        if (! array_is_list($fields) || count($fields) !== 4 || in_array(null, $fields, true)) {
            throw new DomainViolation('invalid_csv_columns', 'Expected exactly date, description, amount and type.');
        }

        return new self($fields[0], $fields[1], $fields[2], $fields[3]);
    }
}
