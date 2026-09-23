<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Data\StoredImportFileData;
use App\Application\Imports\Data\ValidatedImportFileData;
use App\Application\Imports\ImportLimits;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;
use App\Application\Imports\UploadTooLarge;

final readonly class LocalImportFileStorage implements ImportFileStorage
{
    public function __construct(private string $root) {}

    public function store(string $temporaryPath): StoredImportFileData
    {
        $input = @fopen($temporaryPath, 'rb');
        if ($input === false) {
            throw new ImportUnavailable;
        }
        $key = 'imports/'.bin2hex(random_bytes(32)).'.csv';
        $target = $this->path($key);
        $directory = dirname($target);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            fclose($input);
            throw new ImportUnavailable;
        }
        $output = @fopen($target, 'xb');
        if ($output === false) {
            fclose($input);
            throw new ImportUnavailable;
        }
        @chmod($target, 0600);
        $size = 0;
        $hash = hash_init('sha256');
        try {
            while (! feof($input)) {
                $buffer = fread($input, 1048576);
                if ($buffer === false) {
                    throw new ImportUnavailable;
                }
                $size += strlen($buffer);
                if ($size > ImportLimits::MAX_BYTES) {
                    throw new UploadTooLarge;
                }
                hash_update($hash, $buffer);
                $offset = 0;
                while ($offset < strlen($buffer)) {
                    $written = fwrite($output, substr($buffer, $offset));
                    if ($written === false || $written === 0) {
                        throw new ImportUnavailable;
                    }
                    $offset += $written;
                }
            }
            if ($size === 0) {
                throw new InvalidImportFile('empty_csv_file');
            }
            if (! fflush($output) || ! fsync($output)) {
                throw new ImportUnavailable;
            }

            return new StoredImportFileData($key, $size, hash_final($hash));
        } catch (\Throwable $error) {
            @unlink($target);
            throw $error instanceof InvalidImportFile || $error instanceof UploadTooLarge ? $error : new ImportUnavailable;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function delete(string $path): void
    {
        $target = $this->path($path);
        if (is_file($target) && ! @unlink($target)) {
            throw new ImportUnavailable;
        }
    }

    public function validate(StoredImportFileData $file): ValidatedImportFileData
    {
        $path = $this->path($file->path);
        if (! is_file($path)) {
            throw new InvalidImportFile('source_file_missing');
        }
        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new ImportUnavailable;
        }
        try {
            $stat = fstat($input);
            if ($stat === false) {
                throw new ImportUnavailable;
            }
            if ($stat['size'] !== $file->sizeBytes) {
                throw new InvalidImportFile('source_file_changed');
            }
            $hash = hash_init('sha256');
            while (! feof($input)) {
                $buffer = fread($input, 1048576);
                if ($buffer === false) {
                    throw new ImportUnavailable;
                }
                hash_update($hash, $buffer);
            }
            if (! hash_equals($file->checksum, hash_final($hash))) {
                throw new InvalidImportFile('source_file_changed');
            }
            rewind($input);
            // The schema header is one bounded physical line; quoted/multiline data is parsed in chunks later.
            $header = fgets($input, ImportLimits::MAX_HEADER_BYTES + 1);
            if ($header === false || strlen($header) >= ImportLimits::MAX_HEADER_BYTES) {
                throw new InvalidImportFile('invalid_csv_header');
            }
            $offset = ftell($input);
            if ($offset === false) {
                throw new ImportUnavailable;
            }
            $header = str_starts_with($header, "\xEF\xBB\xBF") ? substr($header, 3) : $header;
            if (str_getcsv(rtrim($header, "\r\n"), ',', '"', '') !== ['date', 'description', 'amount', 'type']) {
                throw new InvalidImportFile('invalid_csv_header');
            }

            return new ValidatedImportFileData($offset);
        } catch (InvalidImportFile $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ImportUnavailable;
        } finally {
            fclose($input);
        }
    }

    public function path(string $key): string
    {
        if (preg_match('/\Aimports\/[a-f0-9]{64}\.csv\z/', $key) !== 1) {
            throw new ImportUnavailable;
        }

        return rtrim($this->root, '/').'/'.$key;
    }
}
