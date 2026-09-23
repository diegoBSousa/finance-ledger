<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;
use App\Application\Imports\UploadTooLarge;
use App\Infrastructure\Storage\LocalImportFileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TemporaryUploads;

final class ImportFileStorageTest extends TestCase
{
    use TemporaryUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUploadRoot();
    }

    protected function tearDown(): void
    {
        $this->removeUploadRoot();
        parent::tearDown();
    }

    public function test_storage_uses_random_private_names_exact_size_and_checksum(): void
    {
        $store = new LocalImportFileStorage($this->uploadRoot);
        $source = $this->sourceFile();
        $file = $store->store($source);
        self::assertSame(filesize($source), $file->sizeBytes);
        self::assertSame(hash_file('sha256', $source), $file->checksum);
        self::assertMatchesRegularExpression('/\Aimports\/[a-f0-9]{64}\.csv\z/', $file->path);
        self::assertSame(0600, fileperms($store->path($file->path)) & 0777);
        self::assertSame(strlen("date,description,amount,type\n"), $store->validate($file)->headerOffset);
        self::assertNotSame($file->path, $store->store($source)->path);
        $store->delete($file->path);
        self::assertFileDoesNotExist($store->path($file->path));
    }

    public function test_bom_crlf_and_quoted_header_preserve_the_exact_byte_offset(): void
    {
        $store = new LocalImportFileStorage($this->uploadRoot);
        $header = "\xEF\xBB\xBF\"date\",\"description\",\"amount\",\"type\"\r\n";
        $file = $store->store($this->sourceFile($header."2026-08-16,test #682,10,Receita\r\n"));
        self::assertSame(strlen($header), $store->validate($file)->headerOffset);
    }

    #[DataProvider('invalidHeaders')]
    public function test_invalid_or_unbounded_header_is_rejected_in_preparation(string $header): void
    {
        $store = new LocalImportFileStorage($this->uploadRoot);
        $file = $store->store($this->sourceFile($header));
        $this->expectException(InvalidImportFile::class);
        $store->validate($file);
    }

    public static function invalidHeaders(): iterable
    {
        yield ['date;description;amount;type'];
        yield ['bad,description,amount,type'];
        yield [str_repeat('a', 4096)];
        yield ["PK\x03\x04archive"];
    }

    public function test_same_size_tampering_is_detected_by_checksum(): void
    {
        $store = new LocalImportFileStorage($this->uploadRoot);
        $file = $store->store($this->sourceFile());
        $handle = fopen($store->path($file->path), 'r+b');
        fwrite($handle, 'x');
        fclose($handle);
        $this->expectException(InvalidImportFile::class);
        $store->validate($file);
    }

    public function test_paths_outside_owned_namespace_cannot_be_deleted(): void
    {
        $store = new LocalImportFileStorage($this->uploadRoot);
        $source = $this->sourceFile();
        try {
            $store->delete('../'.basename($source));
            self::fail('Traversal must fail.');
        } catch (ImportUnavailable) {
            self::assertFileExists($source);
        }
    }

    public function test_exact_100mb_is_accepted_and_one_extra_byte_removes_the_partial_copy(): void
    {
        $store = new LocalImportFileStorage($this->uploadRoot);
        $source = $this->sourceFile();
        $handle = fopen($source, 'r+b');
        ftruncate($handle, 100000000);
        fclose($handle);
        $file = $store->store($source);
        self::assertSame(100000000, $file->sizeBytes);
        $store->delete($file->path);
        $handle = fopen($source, 'r+b');
        ftruncate($handle, 100000001);
        fclose($handle);
        try {
            $store->store($source);
            self::fail('The byte limit is exact.');
        } catch (UploadTooLarge) {
            self::assertSame([], glob($this->uploadRoot.'/imports/*'));
        }
    }

    public function test_empty_upload_removes_the_partial_copy(): void
    {
        $store = new LocalImportFileStorage($this->uploadRoot);
        try {
            $store->store($this->sourceFile(''));
            self::fail('Empty must fail.');
        } catch (InvalidImportFile) {
            self::assertSame([], glob($this->uploadRoot.'/imports/*'));
        }
    }
}
