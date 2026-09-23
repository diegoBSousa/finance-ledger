<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostCsvBatchRequest;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\PostCsvBatchUseCase;
use App\Application\Accounting\PrepareCsvPostingUseCase;
use App\Application\Imports\CsvRowCanonicalizer;
use App\Application\Imports\Data\CsvRowData;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Doubles\InMemoryAccountRepository;
use Tests\Doubles\InMemoryJournalRepository;

final class PostCsvBatchUseCaseTest extends TestCase
{
    private function useCase(JournalRepository $repo): PostCsvBatchUseCase
    {
        return new PostCsvBatchUseCase(new PrepareCsvPostingUseCase(new InMemoryAccountRepository(Accounts::data()), new CsvRowCanonicalizer), $repo);
    }

    public function test_posting_a_parsed_batch_returns_ordered_results_without_the_framework(): void
    {
        $useCase = $this->useCase(new InMemoryJournalRepository(Accounts::data()));
        $row = new CsvRowData('2026-08-16', 'Serviços de Limpeza #682', '494618', 'Despesa');
        $response = $useCase->execute(new PostCsvBatchRequest('7', [$row, $row]));
        self::assertSame(['inserted', 'duplicate'], array_column($response->entries, 'status'));
        self::assertSame($response->entries[0]->journalEntryId, $response->entries[1]->journalEntryId);
    }

    public function test_invalid_or_unauthorized_later_rows_do_not_reach_the_writer(): void
    {
        $writer = $this->createMock(JournalRepository::class);
        $writer->expects(self::never())->method('post');
        $this->expectException(DomainViolation::class);
        $this->useCase($writer)->execute(new PostCsvBatchRequest('7', [
            new CsvRowData('2026-08-16', 'A #682', '1', 'Receita'),
            new CsvRowData('2026-08-16', 'B #683', '1', 'Receita'),
        ]));
    }

    #[DataProvider('invalidBatchSizes')]
    public function test_empty_or_oversized_batches_do_not_reach_the_writer(int $size): void
    {
        $writer = $this->createMock(JournalRepository::class);
        $writer->expects(self::never())->method('post');
        $this->expectException(DomainViolation::class);
        $this->useCase($writer)->execute(new PostCsvBatchRequest('7', array_fill(0, $size,
            new CsvRowData('2026-08-16', 'A #682', '1', 'Receita'))));
    }

    public static function invalidBatchSizes(): iterable
    {
        yield 'empty' => [0];
        yield 'too large' => [PostingBatchData::MAX_ENTRIES + 1];
    }

    public function test_persistence_text_limit_is_checked_before_writing(): void
    {
        $writer = $this->createMock(JournalRepository::class);
        $writer->expects(self::never())->method('post');
        $this->expectException(DomainViolation::class);
        $this->useCase($writer)->execute(new PostCsvBatchRequest('7', [
            new CsvRowData('2026-08-16', str_repeat('a', 65536).' #682', '1', 'Receita'),
        ]));
    }
}
