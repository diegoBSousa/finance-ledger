<?php

declare(strict_types=1);

namespace App\Application\Imports\Contracts;

use App\Application\Imports\Data\CsvChunkData;
use App\Application\Imports\Data\ReadCsvChunkData;

interface CsvChunkReader
{
    /** Read only complete bounded records, verifying every consumed file block. */
    public function read(ReadCsvChunkData $request): CsvChunkData;
}
