<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use RuntimeException;

final class CacheUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dashboard cache is temporarily unavailable.');
    }
}
