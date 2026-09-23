<?php

declare(strict_types=1);

namespace App\Application\Imports;

use RuntimeException;

final class InvalidImportFile extends RuntimeException
{
    public function __construct(public readonly string $reason = 'invalid_csv_file')
    {
        parent::__construct('The uploaded CSV is invalid.');
    }
}
