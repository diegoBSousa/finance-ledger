<?php

declare(strict_types=1);

namespace App\Application\Imports;

use RuntimeException;

final class ImportNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Import not found.');
    }
}
