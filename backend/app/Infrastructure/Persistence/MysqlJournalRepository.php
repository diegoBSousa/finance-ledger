<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostedJournalData;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\Data\PostingBatchResultData;
use App\Application\Accounting\Data\PostJournalData;
use App\Application\Accounting\PostingUnavailable;
use App\Application\Accounting\PreparedPostingValidator;
use App\Domain\Shared\DomainViolation;
use App\Infrastructure\Persistence\Models\AccountRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;

final readonly class MysqlJournalRepository implements JournalRepository
{
    public function __construct(private PreparedPostingValidator $validator) {}

    public function post(PostingBatchData $batch): PostingBatchResultData
    {
        try {
            return DB::transaction(fn () => $this->persist($batch), attempts: 3);
        } catch (PDOException) {
            // SQL details and Laravel exceptions do not cross the application port.
            throw new PostingUnavailable;
        }
    }

    private function persist(PostingBatchData $batch): PostingBatchResultData
    {
        // One authenticated owner per batch. Always lock owner -> projections -> accounts.
        $state = DB::table('financial_states')->where('owner_user_id', $batch->actorUserId)->lockForUpdate()->first();
        if ($state === null) {
            throw new PostingUnavailable;
        }
        $accountIds = [];
        foreach ($batch->entries as $entry) {
            $accountIds[] = $entry->financialAccountId;
            foreach ($entry->journal->postings as $posting) {
                $accountIds[] = $posting->accountId;
            }
        }
        $accountIds = array_values(array_unique($accountIds));
        usort($accountIds, fn (string $left, string $right) => (int) $left <=> (int) $right);
        foreach ($accountIds as $id) {
            if (DB::table('account_balances')->where('account_id', $id)->lockForUpdate()->first() === null) {
                throw new PostingUnavailable;
            }
        }
        $accounts = [];
        foreach ($accountIds as $id) {
            $record = AccountRecord::query()->whereKey($id)->lockForUpdate()->first();
            if ($record === null) {
                throw new PostingUnavailable;
            }
            $accounts[] = $record->toData();
        }
        $this->validator->validateBatch($batch->entries, $accounts);

        $results = [];
        $insertedIds = [];
        $changedAccounts = [];
        foreach ($batch->entries as $entry) {
            $existing = $this->existing($batch->actorUserId, $entry);
            if ($existing !== null) {
                $results[] = $existing;

                continue;
            }
            try {
                $id = (string) DB::table('journal_entries')->insertGetId([
                    'owner_user_id' => $batch->actorUserId,
                    'uploaded_by_user_id' => $batch->actorUserId,
                    'financial_account_id' => $entry->financialAccountId,
                    'posting_date' => $entry->journal->transactionDate,
                    'description' => $entry->journal->description,
                    'original_description' => $entry->originalDescription,
                    'movement_type' => $entry->movementType,
                    'source_row_hash' => $entry->sourceRowHash,
                    'canonical_record' => $entry->canonicalRecord,
                    'currency' => 'BRL',
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // Only the expected owner/hash unique index can represent an idempotent retry.
                if (preg_match("/for key '(?:journal_entries\.)?journal_owner_hash_unique'/", $exception->errorInfo[2] ?? '') !== 1) {
                    throw $exception;
                }
                $results[] = $this->existing($batch->actorUserId, $entry) ?? throw $exception;

                continue;
            }
            $postings = [];
            foreach ($entry->journal->postings as $position => $posting) {
                $postings[] = [
                    'journal_entry_id' => $id, 'owner_user_id' => $batch->actorUserId,
                    'account_id' => $posting->accountId, 'position' => $position + 1,
                    'side' => $posting->side, 'amount_minor' => $posting->amountMinor,
                    'currency' => $posting->currency,
                ];
                $changedAccounts[$posting->accountId] = true;
            }
            DB::table('ledger_entries')->insert($postings);
            $insertedIds[] = $id;
            $results[] = new PostedJournalData($entry->sourceRowHash, $id, 'inserted');
        }
        if ($insertedIds !== []) {
            $changedIds = array_map('strval', array_keys($changedAccounts));
            usort($changedIds, fn (string $left, string $right) => (int) $left <=> (int) $right);
            $event = DB::table('outbox_events')->insertGetId([
                'event_type' => 'LedgerChanged', 'event_version' => 1,
                'payload' => json_encode([
                    'owner_user_id' => $batch->actorUserId, 'currency' => 'BRL',
                    'journal_entry_ids' => $insertedIds, 'account_ids' => $changedIds,
                    'financial_revision' => (string) DB::table('financial_states')
                        ->where('owner_user_id', $batch->actorUserId)->value('revision'),
                ], JSON_THROW_ON_ERROR),
            ]);
            DB::table('outbox_deliveries')->insert(['event_id' => $event, 'consumer' => 'dashboard-cache-invalidator']);
        }

        return new PostingBatchResultData($results);
    }

    private function existing(string $owner, PostJournalData $entry): ?PostedJournalData
    {
        // A locking read sees the latest committed version even in an older REPEATABLE READ transaction.
        $existing = DB::table('journal_entries')->where('owner_user_id', $owner)
            ->where('source_row_hash', $entry->sourceRowHash)->lockForUpdate()->first();
        if ($existing === null) {
            return null;
        }
        if ($existing->canonical_record !== $entry->canonicalRecord) {
            throw new DomainViolation('idempotency_conflict', 'The stored operation has a different canonical identity.');
        }
        $postings = DB::table('ledger_entries')->where('journal_entry_id', $existing->id)->orderBy('position')->lockForUpdate()->get();
        if ($postings->count() !== 2 || (string) $existing->financial_account_id !== $entry->financialAccountId
            || $existing->posting_date !== $entry->journal->transactionDate
            || $existing->description !== $entry->journal->description || $existing->movement_type !== $entry->movementType) {
            throw new DomainViolation('inconsistent_ledger', 'The stored operation does not match its canonical identity.');
        }
        foreach ($postings as $position => $posting) {
            $expected = $entry->journal->postings[$position];
            if ((int) $posting->position !== $position + 1 || (string) $posting->account_id !== $expected->accountId
                || $posting->side !== $expected->side || (string) $posting->amount_minor !== $expected->amountMinor
                || $posting->currency !== $expected->currency) {
                throw new DomainViolation('inconsistent_ledger', 'The stored postings do not match the operation.');
            }
        }

        return new PostedJournalData($entry->sourceRowHash, (string) $existing->id, 'duplicate');
    }
}
