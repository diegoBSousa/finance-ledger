<?php

declare(strict_types=1);

namespace App\Application\Imports;

use RuntimeException;

final class UploadTooLarge extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The CSV must not exceed 100000000 bytes.');
    }
}
