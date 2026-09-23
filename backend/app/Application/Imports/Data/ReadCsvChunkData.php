<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Application\Imports\ImportUnavailable;

final readonly class ReadCsvChunkData
{
    /** @var list<string> */
    public array $blockHashes;

    /** @param array<array-key,string> $blockHashes */
    public function __construct(public StoredImportFileData $file, public int $byteOffset, public string $lastRecordNumber, array $blockHashes)
    {
        if (! array_is_list($blockHashes)) {
            throw new ImportUnavailable;
        }
        foreach ($blockHashes as $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                throw new ImportUnavailable;
            }
        }
        $this->blockHashes = $blockHashes;
    }
}
