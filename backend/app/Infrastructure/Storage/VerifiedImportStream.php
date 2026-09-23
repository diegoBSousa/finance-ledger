<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Imports\ChunkLimits;
use App\Application\Imports\Data\ReadCsvChunkData;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;

/** Bounded random access: each byte comes from a block verified against the prepared manifest. */
final class VerifiedImportStream
{
    /** @var resource */
    private $stream;

    private string $buffer = '';

    private int $blockIndex = -1;

    public int $position;

    public function __construct(string $path, private readonly ReadCsvChunkData $request)
    {
        if ($request->byteOffset < 0 || $request->byteOffset > $request->file->sizeBytes
            || count($request->blockHashes) !== (int) ceil($request->file->sizeBytes / ChunkLimits::INTEGRITY_BLOCK_BYTES)) {
            throw new ImportUnavailable;
        }
        if (! is_file($path)) {
            throw new InvalidImportFile('source_file_missing');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new ImportUnavailable;
        }
        $this->stream = $stream;
        $stat = fstat($stream);
        if ($stat === false || $stat['size'] !== $request->file->sizeBytes) {
            fclose($stream);
            throw new InvalidImportFile('source_file_changed');
        }
        $this->position = $request->byteOffset;
    }

    public function next(): ?string
    {
        if ($this->position === $this->request->file->sizeBytes) {
            return null;
        }
        $index = intdiv($this->position, ChunkLimits::INTEGRITY_BLOCK_BYTES);
        if ($index !== $this->blockIndex) {
            $start = $index * ChunkLimits::INTEGRITY_BLOCK_BYTES;
            if (fseek($this->stream, $start) !== 0) {
                throw new ImportUnavailable;
            }
            $length = min(ChunkLimits::INTEGRITY_BLOCK_BYTES, $this->request->file->sizeBytes - $start);
            $this->buffer = '';
            while (strlen($this->buffer) < $length) {
                $part = fread($this->stream, $length - strlen($this->buffer));
                if ($part === false) {
                    throw new ImportUnavailable;
                }
                if ($part === '') {
                    throw new InvalidImportFile('source_file_changed');
                }
                $this->buffer .= $part;
            }
            if (! hash_equals($this->request->blockHashes[$index], hash('sha256', $this->buffer))) {
                throw new InvalidImportFile('source_file_changed');
            }
            $this->blockIndex = $index;
        }

        return $this->buffer[$this->position++ % ChunkLimits::INTEGRITY_BLOCK_BYTES];
    }

    public function close(): void
    {
        fclose($this->stream);
    }
}
