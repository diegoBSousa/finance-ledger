<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class CsvChunkData
{
    /** @param list<CsvRecordData> $records */
    public function __construct(public array $records, public int $nextByteOffset, public string $lastRecordNumber, public bool $eof) {}
}
