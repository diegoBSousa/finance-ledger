<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Imports\CsvRowCanonicalizer;
use App\Application\Imports\Data\CsvRowData;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvRowCanonicalizerTest extends TestCase
{
    private const string GOLDEN_JSON = '["csv-row-v1","BRL","2026-08-16","Serviços de Limpeza","494618","expense","682"]';

    private const string GOLDEN_HASH = 'd4862cdd783f053103511bf4f49bfb5438fce54b40bbabc58163b424e1136a8b';

    public function test_reference_row_has_a_frozen_canonical_record_and_hash(): void
    {
        $row = (new CsvRowCanonicalizer)->canonicalize(new CsvRowData('2026-08-16', 'Serviços de Limpeza #682', '494618', 'Despesa'));
        self::assertSame(self::GOLDEN_JSON, $row->canonicalRecord);
        self::assertSame(self::GOLDEN_HASH, $row->sourceRowHash);
        self::assertSame('682', $row->accountNumber);
        self::assertSame('494618', $row->amountMinor);
    }

    public function test_unicode_whitespace_and_leading_zero_variations_keep_the_identity(): void
    {
        $row = new CsvRowData(' 2026-08-16 ', "\u{00A0}Servic\u{0327}os de Limpeza #00682\t", '000494618', ' DESPESA ');
        self::assertSame(self::GOLDEN_HASH, (new CsvRowCanonicalizer)->canonicalize($row)->sourceRowHash);
    }

    public function test_special_character_escaping_is_part_of_the_frozen_identity_contract(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/canonical-special-characters.json'), true, flags: JSON_THROW_ON_ERROR);
        $row = (new CsvRowCanonicalizer)->canonicalize(CsvRowData::fromFields($fixture['fields']));
        self::assertSame($fixture['canonical_record'], $row->canonicalRecord);
        self::assertSame($fixture['sha256'], $row->sourceRowHash);
    }

    public function test_csv_quoting_and_record_endings_do_not_change_the_identity(): void
    {
        $rows = [
            "2026-08-16,Serviços de Limpeza #682,494618,Despesa\n",
            "\"2026-08-16\",\"Serviços de Limpeza #682\",\"494618\",\"Despesa\"\r\n",
        ];

        foreach ($rows as $csv) {
            $row = CsvRowData::fromFields(str_getcsv($csv, ',', '"', ''));
            self::assertSame(self::GOLDEN_HASH, (new CsvRowCanonicalizer)->canonicalize($row)->sourceRowHash);
        }
    }

    public function test_csv_parser_preserves_commas_quotes_and_embedded_newlines(): void
    {
        $csv = "2026-08-16,\"Limpeza, \"\"especial\"\"\nsegundo andar #682\",494618,Despesa";
        $row = (new CsvRowCanonicalizer)->canonicalize(CsvRowData::fromFields(str_getcsv($csv, ',', '"', '')));
        self::assertSame("Limpeza, \"especial\"\nsegundo andar", $row->description);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function changedRows(): iterable
    {
        yield 'date' => ['2026-08-17', 'Serviços de Limpeza #682', '494618', 'Despesa'];
        yield 'account' => ['2026-08-16', 'Serviços de Limpeza #683', '494618', 'Despesa'];
        yield 'amount' => ['2026-08-16', 'Serviços de Limpeza #682', '494619', 'Despesa'];
        yield 'type' => ['2026-08-16', 'Serviços de Limpeza #682', '494618', 'Receita'];
        yield 'case' => ['2026-08-16', 'serviços de Limpeza #682', '494618', 'Despesa'];
        yield 'accent' => ['2026-08-16', 'Servicos de Limpeza #682', '494618', 'Despesa'];
        yield 'internal space' => ['2026-08-16', 'Serviços  de Limpeza #682', '494618', 'Despesa'];
    }

    #[DataProvider('changedRows')]
    public function test_meaningful_changes_change_the_identity(string $date, string $description, string $amount, string $type): void
    {
        $row = (new CsvRowCanonicalizer)->canonicalize(new CsvRowData($date, $description, $amount, $type));
        self::assertNotSame(self::GOLDEN_HASH, $row->sourceRowHash);
    }

    public function test_partial_reordered_and_overlapping_inputs_keep_the_same_identity_set(): void
    {
        $first = new CsvRowData('2026-08-16', 'Serviços de Limpeza #682', '494618', 'Despesa');
        $second = new CsvRowData('2026-08-17', 'Recebimento #682', '600000', 'Receita');
        $third = new CsvRowData('2026-08-18', 'Compra #683', '100', 'Despesa');
        $hash = fn (CsvRowData $row): string => (new CsvRowCanonicalizer)->canonicalize($row)->sourceRowHash;
        $complete = array_map($hash, [$first, $second, $third]);
        $overlap = array_unique(array_map($hash, [$second, $first, $third, $second]));
        sort($complete);
        sort($overlap);
        self::assertSame($complete, $overlap);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidRows(): iterable
    {
        yield 'impossible date' => ['2026-02-29', 'Compra #682', '100', 'Despesa'];
        yield 'non ISO date' => ['16/08/2026', 'Compra #682', '100', 'Despesa'];
        yield 'date with time' => ['2026-08-16T00:00:00', 'Compra #682', '100', 'Despesa'];
        yield 'no suffix' => ['2026-08-16', 'Compra', '100', 'Despesa'];
        yield 'text after account' => ['2026-08-16', 'Compra #682 extra', '100', 'Despesa'];
        yield 'empty description' => ['2026-08-16', ' #682', '100', 'Despesa'];
        yield 'zero account' => ['2026-08-16', 'Compra #000', '100', 'Despesa'];
        yield 'overflow account' => ['2026-08-16', 'Compra #9223372036854775808', '100', 'Despesa'];
        yield 'unsupported type' => ['2026-08-16', 'Compra #682', '100', 'Transferência'];
        yield 'fractional amount' => ['2026-08-16', 'Compra #682', '1.00', 'Despesa'];
        yield 'negative amount' => ['2026-08-16', 'Compra #682', '-100', 'Despesa'];
        yield 'invalid UTF-8' => ['2026-08-16', "Compra\xFF #682", '100', 'Despesa'];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_rows_are_rejected(string $date, string $description, string $amount, string $type): void
    {
        $this->expectException(DomainViolation::class);
        (new CsvRowCanonicalizer)->canonicalize(new CsvRowData($date, $description, $amount, $type));
    }

    public function test_leap_day_and_the_last_account_suffix_are_accepted(): void
    {
        $row = (new CsvRowCanonicalizer)->canonicalize(new CsvRowData('2024-02-29', 'Pedido #123 #682', '100', 'Receita'));
        self::assertSame('Pedido #123', $row->description);
        self::assertSame('682', $row->accountNumber);
    }

    public function test_unexpected_csv_column_count_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        CsvRowData::fromFields(['2026-08-16', 'Compra #682', '100', 'Despesa', 'extra']);
    }

    public function test_empty_csv_record_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        CsvRowData::fromFields([null]);
    }
}
