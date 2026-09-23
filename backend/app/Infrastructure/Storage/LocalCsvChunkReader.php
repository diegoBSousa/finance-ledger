<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Imports\ChunkLimits;
use App\Application\Imports\Contracts\CsvChunkReader;
use App\Application\Imports\Data\CsvChunkData;
use App\Application\Imports\Data\CsvRecordData;
use App\Application\Imports\Data\CsvRowData;
use App\Application\Imports\Data\ReadCsvChunkData;
use App\Application\Imports\InvalidImportFile;
use App\Domain\Shared\DomainViolation;

final readonly class LocalCsvChunkReader implements CsvChunkReader
{
    public function __construct(private LocalImportFileStorage $files) {}

    public function read(ReadCsvChunkData $request): CsvChunkData
    {
        $stream = new VerifiedImportStream($this->files->path($request->file->path), $request);
        $records = [];
        $number = (int) $request->lastRecordNumber;
        $started = hrtime(true);
        try {
            while ($stream->position < $request->file->sizeBytes && count($records) < ChunkLimits::RECORDS) {
                $raw = $this->record($stream);
                $number++;
                try {
                    $row = CsvRowData::fromFields(str_getcsv($raw, ',', '"', ''));
                    $records[] = new CsvRecordData((string) $number, $row);
                } catch (DomainViolation $error) {
                    $records[] = new CsvRecordData((string) $number, null, $error->reason);
                }
                if ($stream->position - $request->byteOffset >= ChunkLimits::BYTES
                    || hrtime(true) - $started >= ChunkLimits::READ_SECONDS * 1000000000) {
                    break;
                }
            }

            return new CsvChunkData($records, $stream->position, (string) $number, $stream->position === $request->file->sizeBytes);
        } finally {
            $stream->close();
        }
    }

    private function record(VerifiedImportStream $stream): string
    {
        // 0: field start, 1: unquoted, 2: quoted, 3: closing quote (or first of a doubled quote).
        $state = 0;
        $raw = '';
        while (($char = $stream->next()) !== null) {
            if ($state !== 2 && ($char === "\n" || $char === "\r")) {
                if ($char === "\r" && $stream->next() !== "\n") {
                    throw new InvalidImportFile('malformed_csv');
                }

                return $raw;
            }
            if (strlen($raw) >= ChunkLimits::RECORD_BYTES) {
                throw new InvalidImportFile('csv_record_too_large');
            }
            $raw .= $char;
            if ($state === 2) {
                if ($char === '"') {
                    $state = 3;
                }
            } elseif ($char === ',') {
                $state = 0;
            } elseif ($char === '"') {
                if ($state === 1) {
                    throw new InvalidImportFile('malformed_csv');
                }
                $state = 2;
            } elseif ($state === 3) {
                throw new InvalidImportFile('malformed_csv');
            } else {
                $state = 1;
            }
        }
        if ($state === 2) {
            throw new InvalidImportFile('malformed_csv');
        }

        return $raw;
    }
}
