<?php

declare(strict_types=1);

namespace App\Application\Imports;

final class ImportLimits
{
    public const int MAX_BYTES = 100000000;

    public const int MAX_BODY_BYTES = 110000000;

    public const int MAX_HEADER_BYTES = 4096;
}
