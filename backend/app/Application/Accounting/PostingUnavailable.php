<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use RuntimeException;

final class PostingUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Financial posting is temporarily unavailable.');
    }
}
