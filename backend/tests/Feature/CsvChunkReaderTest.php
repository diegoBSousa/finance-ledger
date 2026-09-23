<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Imports\ChunkLimits;
use App\Application\Imports\Data\ReadCsvChunkData;
use App\Application\Imports\InvalidImportFile;
use App\Infrastructure\Storage\LocalCsvChunkReader;
use App\Infrastructure\Storage\LocalImportFileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TemporaryUploads;

final class CsvChunkReaderTest extends TestCase
{
    use TemporaryUploads;

    private LocalImportFileStorage $files;

    private LocalCsvChunkReader $reader;

    protected function setUp(): void
    {
        $this->createUploadRoot();
        $this->files = new LocalImportFileStorage($this->uploadRoot);
        $this->reader = new LocalCsvChunkReader($this->files);
    }

    protected function tearDown(): void
    {
        $this->removeUploadRoot();
    }

    private function request(string $content): ReadCsvChunkData
    {
        $file = $this->files->store($this->sourceFile($content));
        $validated = $this->files->validate($file);

        return new ReadCsvChunkData($file, $validated->headerOffset, '1', $validated->blockHashes);
    }

    public function test_quoted_multiline_commas_escaped_quotes_bom_crlf_and_no_final_newline(): void
    {
        $request = $this->request("\xEF\xBB\xBFdate,description,amount,type\r\n2026-08-16,\"Serviço, \"\"A\"\"\r\nsegunda linha #682\",10,Receita\r\n2026-08-16,Fim #682,20,Despesa");
        $chunk = $this->reader->read($request);
        self::assertCount(2, $chunk->records);
        self::assertSame("Serviço, \"A\"\r\nsegunda linha #682", $chunk->records[0]->row->description);
        self::assertSame(['2', '3'], array_column($chunk->records, 'number'));
        self::assertSame($request->file->sizeBytes, $chunk->nextByteOffset);
        self::assertTrue($chunk->eof);
    }

    public function test_500_record_boundary_resumes_at_next_complete_record(): void
    {
        $row = "2026-08-16,Row #682,10,Receita\n";
        $request = $this->request("date,description,amount,type\n".str_repeat($row, 501));
        $first = $this->reader->read($request);
        self::assertCount(500, $first->records);
        self::assertFalse($first->eof);
        $last = $this->reader->read(new ReadCsvChunkData($request->file, $first->nextByteOffset, $first->lastRecordNumber, $request->blockHashes));
        self::assertCount(1, $last->records);
        self::assertSame('502', $last->lastRecordNumber);
        self::assertTrue($last->eof);
    }

    public function test_byte_boundary_and_integrity_blocks_do_not_split_a_record(): void
    {
        $row = '2026-08-16,"'.str_repeat('a', 16000)."\n#682\",10,Receita\n";
        $request = $this->request("date,description,amount,type\n".str_repeat($row, 100));
        self::assertCount(2, $request->blockHashes);
        $first = $this->reader->read($request);
        self::assertGreaterThan(ChunkLimits::BYTES, $first->nextByteOffset - $request->byteOffset);
        self::assertLessThan(ChunkLimits::BYTES + ChunkLimits::RECORD_BYTES + 2, $first->nextByteOffset - $request->byteOffset);
        $last = $this->reader->read(new ReadCsvChunkData($request->file, $first->nextByteOffset, $first->lastRecordNumber, $request->blockHashes));
        self::assertSame(100, count($first->records) + count($last->records));
        self::assertTrue($last->eof);
    }

    public function test_wrong_columns_and_blank_records_are_recoverable_results(): void
    {
        $chunk = $this->reader->read($this->request("date,description,amount,type\n\nwrong,columns\n2026-08-16,Row #682,10,Receita\n"));
        self::assertSame(['invalid_csv_columns', 'invalid_csv_columns', null], array_column($chunk->records, 'errorCode'));
        self::assertSame('4', $chunk->lastRecordNumber);
    }

    #[DataProvider('malformed')]
    public function test_malformed_or_unbounded_record_stops_the_chunk(string $body, string $reason): void
    {
        try {
            $this->reader->read($this->request("date,description,amount,type\n".$body));
            self::fail('Malformed CSV accepted.');
        } catch (InvalidImportFile $error) {
            self::assertSame($reason, $error->reason);
        }
    }

    public static function malformed(): array
    {
        return [
            ['2026-08-16,"unterminated', 'malformed_csv'],
            ['2026-08-16,"closed"junk,10,Receita', 'malformed_csv'],
            ['2026-08-16,un"quoted,10,Receita', 'malformed_csv'],
            ["2026-08-16,Row #682,10,Receita\rX", 'malformed_csv'],
            ['"'.str_repeat('a', 65536).'"', 'csv_record_too_large'],
        ];
    }

    public function test_same_size_change_is_detected_before_processing_bytes(): void
    {
        $request = $this->request("date,description,amount,type\n2026-08-16,Row #682,10,Receita\n");
        $path = $this->files->path($request->file->path);
        file_put_contents($path, str_replace(',10,', ',99,', file_get_contents($path)));
        try {
            $this->reader->read($request);
            self::fail('Changed file accepted.');
        } catch (InvalidImportFile $error) {
            self::assertSame('source_file_changed', $error->reason);
        }
    }

    public function test_header_only_file_finishes_without_inventing_a_data_record(): void
    {
        $chunk = $this->reader->read($this->request("date,description,amount,type\n"));
        self::assertSame([], $chunk->records);
        self::assertSame('1', $chunk->lastRecordNumber);
        self::assertTrue($chunk->eof);
    }
}
