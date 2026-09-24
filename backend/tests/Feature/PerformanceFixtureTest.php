<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Imports\CsvRowCanonicalizer;
use App\Application\Imports\Data\CsvRowData;
use PHPUnit\Framework\TestCase;
use Tests\Performance\SyntheticCsv;

final class PerformanceFixtureTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/performance-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_exact_bytes_contain_valid_unique_financial_records_and_independent_controls(): void
    {
        $path = $this->directory.'/exact.csv';
        $control = SyntheticCsv::create($path, 'fixture', 1000, bytes: 500000);
        self::assertSame(500000, filesize($path));
        self::assertSame(hash_file('sha256', $path), $control['sha256']);
        $rows = $this->canonical($path);
        self::assertCount(1000, $rows);
        self::assertCount(1000, array_unique(array_column($rows, 'sourceRowHash')));
        self::assertSame(50500, $control['income_minor']);
        self::assertSame(18500, $control['expense_minor']);
        self::assertSame(32000, array_sum($control['accounts']));
        self::assertCount(900, array_unique(array_column($rows, 'accountNumber')));
    }

    public function test_overlapping_and_partial_files_keep_identical_canonical_hashes(): void
    {
        SyntheticCsv::create($this->directory.'/a.csv', 'overlap', 1000);
        SyntheticCsv::create($this->directory.'/b.csv', 'overlap', 1000, 501);
        $a = array_column($this->canonical($this->directory.'/a.csv'), 'sourceRowHash');
        $b = array_column($this->canonical($this->directory.'/b.csv'), 'sourceRowHash');
        self::assertCount(500, array_intersect($a, $b));
        self::assertCount(1500, array_unique([...$a, ...$b]));
        self::assertSame(array_slice($a, 500), array_slice($b, 0, 500));
    }

    private function canonical(string $path): array
    {
        $stream = fopen($path, 'rb');
        try {
            self::assertSame(['date', 'description', 'amount', 'type'], fgetcsv($stream, escape: ''));
            $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = (new CsvRowCanonicalizer)->canonicalize(new CsvRowData(...$row));
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
