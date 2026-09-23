<?php

declare(strict_types=1);

namespace App\Application\Imports;

final class ChunkLimits
{
    public const int RECORDS = 500;

    public const int BYTES = 1048576;

    public const int RECORD_BYTES = 65536;

    public const int DESCRIPTION_BYTES = 16384;

    public const int READ_SECONDS = 2;

    public const int INTEGRITY_BLOCK_BYTES = 1048576;

    public const int ATTEMPTS = 5;
}
