<?php

declare(strict_types=1);

namespace App\Application\Imports;

use RuntimeException;

final class ImportUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Imports are temporarily unavailable.');
    }
}
