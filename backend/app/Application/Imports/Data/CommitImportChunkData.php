<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Application\Imports\ChunkLimits;
use App\Domain\Shared\DomainViolation;

final readonly class CommitImportChunkData
{
    /** @var list<PreparedImportRowData> */
    public array $rows;

    /** @param array<array-key,PreparedImportRowData> $rows
     * @param  list<string>  $blockHashes
     */
    public function __construct(public ImportChunkSourceData $source, public CsvChunkData $chunk, array $rows, public array $blockHashes)
    {
        $count = count($rows);
        if (! array_is_list($rows) || $count > ChunkLimits::RECORDS || $count !== count($chunk->records)
            || ($count === 0 && ! $chunk->eof) || $chunk->nextByteOffset < $source->byteOffset
            || ($count > 0 && $chunk->nextByteOffset === $source->byteOffset)
            || $chunk->nextByteOffset > $source->file->sizeBytes || $chunk->eof !== ($chunk->nextByteOffset === $source->file->sizeBytes)
            || $chunk->lastRecordNumber !== (string) ((int) $source->lastRecordNumber + $count)) {
            throw new DomainViolation('invalid_chunk_checkpoint', 'Invalid import checkpoint.');
        }
        foreach ($rows as $index => $row) {
            if ($row->recordNumber !== (string) ((int) $source->lastRecordNumber + $index + 1)
                || $row->recordNumber !== $chunk->records[$index]->number) {
                throw new DomainViolation('invalid_chunk_checkpoint', 'Import record numbers must be contiguous.');
            }
        }
        $this->rows = $rows;
    }
}
