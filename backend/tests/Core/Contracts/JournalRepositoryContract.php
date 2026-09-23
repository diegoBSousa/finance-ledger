<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\Data\PostJournalData;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\PostingData;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\PostingBatches;

abstract class JournalRepositoryContract extends TestCase
{
    abstract protected function repository(): JournalRepository;

    public function test_retry_returns_the_original_id_and_marks_the_result_as_duplicate(): void
    {
        $repo = $this->repository();
        $first = $repo->post(PostingBatches::batch())->entries[0];
        $retry = $repo->post(PostingBatches::batch())->entries[0];
        self::assertSame('inserted', $first->status);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $first->journalEntryId);
        self::assertSame('duplicate', $retry->status);
        self::assertSame($first->journalEntryId, $retry->journalEntryId);
        self::assertSame($first->sourceRowHash, $retry->sourceRowHash);
    }

    public function test_duplicates_within_one_batch_preserve_input_order_and_original_ids(): void
    {
        $a = PostingBatches::entry();
        $b = PostingBatches::entry('Outro serviço #682');
        $result = $this->repository()->post(new PostingBatchData('7', [$a, $a, $b, $a]))->entries;
        self::assertSame(['inserted', 'duplicate', 'inserted', 'duplicate'], array_column($result, 'status'));
        self::assertSame($result[0]->journalEntryId, $result[1]->journalEntryId);
        self::assertSame($result[0]->journalEntryId, $result[3]->journalEntryId);
        self::assertNotSame($result[0]->journalEntryId, $result[2]->journalEntryId);
    }

    public function test_partial_reordered_and_overlapping_batches_only_insert_new_content(): void
    {
        $repo = $this->repository();
        $a = PostingBatches::entry();
        $b = PostingBatches::entry('Pagamento #682', type: 'Receita');
        $c = PostingBatches::entry('Outras despesas #682');
        $original = $repo->post(new PostingBatchData('7', [$b, $a]))->entries;
        $result = $repo->post(new PostingBatchData('7', [$a, $c, $b]))->entries;
        self::assertSame(['duplicate', 'inserted', 'duplicate'], array_column($result, 'status'));
        self::assertSame($original[1]->journalEntryId, $result[0]->journalEntryId);
        self::assertSame($original[0]->journalEntryId, $result[2]->journalEntryId);
    }

    public function test_equivalent_normalized_content_is_a_duplicate(): void
    {
        $repo = $this->repository();
        $original = $repo->post(PostingBatches::batch())->entries[0];
        $variant = PostingBatches::entry(" Serviços de Limpeza #0682 \t", '0494618', ' DESPESA ');
        $result = $repo->post(new PostingBatchData('7', [$variant]))->entries[0];
        self::assertSame('duplicate', $result->status);
        self::assertSame($original->journalEntryId, $result->journalEntryId);
    }

    public function test_two_owners_keep_their_own_operations(): void
    {
        $repo = $this->repository();
        $first = $repo->post(PostingBatches::batch())->entries[0];
        $other = $repo->post(new PostingBatchData('8', [PostingBatches::entry('Serviços de Limpeza #683', owner: '8')]))->entries[0];
        self::assertSame('inserted', $other->status);
        self::assertNotSame($first->journalEntryId, $other->journalEntryId);
    }

    public function test_an_unbalanced_later_operation_does_not_commit_the_first_one(): void
    {
        $repo = $this->repository();
        $valid = PostingBatches::entry();
        $broken = new PostJournalData(
            $valid->financialAccountId, $valid->financialAccountNumber, $valid->movementType,
            $valid->sourceRowHash, $valid->canonicalRecord, $valid->originalDescription,
            new JournalEntryData('7', $valid->journal->transactionDate, $valid->journal->description, [
                $valid->journal->postings[0], new PostingData('42', 'credit', '1', 'BRL'),
            ]),
        );
        try {
            $repo->post(new PostingBatchData('7', [$valid, $broken]));
            self::fail('An invalid draft must reject the entire batch.');
        } catch (DomainViolation $exception) {
            self::assertSame('invalid_prepared_posting', $exception->reason);
        }
        self::assertSame('inserted', $repo->post(PostingBatches::batch())->entries[0]->status);
    }

    public function test_the_hash_must_match_the_canonical_operation(): void
    {
        $repo = $this->repository();
        $valid = PostingBatches::entry();
        $broken = new PostJournalData($valid->financialAccountId, $valid->financialAccountNumber, $valid->movementType,
            str_repeat('a', 64), $valid->canonicalRecord, $valid->originalDescription, $valid->journal);
        $this->expectException(DomainViolation::class);
        $repo->post(new PostingBatchData('7', [$broken]));
    }

    public function test_prepared_data_cannot_forge_account_ownership(): void
    {
        $repo = $this->repository();
        $valid = PostingBatches::entry();
        $broken = new PostJournalData($valid->financialAccountId, $valid->financialAccountNumber, $valid->movementType,
            $valid->sourceRowHash, $valid->canonicalRecord, $valid->originalDescription,
            new JournalEntryData('8', $valid->journal->transactionDate, $valid->journal->description, $valid->journal->postings));
        $this->expectException(DomainViolation::class);
        $repo->post(new PostingBatchData('8', [$broken]));
    }
}
