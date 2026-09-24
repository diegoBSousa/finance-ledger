<?php

declare(strict_types=1);

namespace App\Application\Shared;

use RuntimeException;

final class ReadUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Financial data is temporarily unavailable.');
    }
}
