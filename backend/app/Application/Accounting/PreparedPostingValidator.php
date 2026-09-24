<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Application\Accounting\Data\PostJournalData;
use App\Application\Imports\CsvRowCanonicalizer;
use App\Application\Imports\Data\CsvRowData;
use App\Domain\Accounting\Account;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\MovementType;
use App\Domain\Accounting\PostingDate;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;

/** Revalidate the draft against current account data while the adapter holds its locks. */
final class PreparedPostingValidator
{
    /** @param list<AccountData> $accounts */
    public function validate(PostJournalData $entry, array $accounts): void
    {
        $this->validateBatch([$entry], $accounts);
    }

    /**
     * @param  list<PostJournalData>  $entries
     * @param  list<AccountData>  $accounts
     */
    public function validateBatch(array $entries, array $accounts): void
    {
        $byId = [];
        foreach ($accounts as $data) {
            $byId[$data->id] = Account::fromData($data);
        }
        foreach ($entries as $entry) {
            $this->validateEntry($entry, $byId);
        }
    }

    /** @param array<int|string, Account> $byId */
    private function validateEntry(PostJournalData $entry, array $byId): void
    {
        $financial = $byId[$entry->financialAccountId] ?? throw new DomainViolation('account_not_found', 'The financial account does not exist.');
        $type = MovementType::tryFrom($entry->movementType) ?? throw new DomainViolation('invalid_movement_type', 'Unknown movement type.');
        $counterpartId = $entry->journal->postings[$type === MovementType::Income ? 1 : 0]->accountId;
        $counterpart = $byId[$counterpartId] ?? throw new DomainViolation('counterpart_not_found', 'The technical account does not exist.');
        if ($financial->ownerUserId !== $entry->journal->ownerUserId || $counterpart->ownerUserId !== $entry->journal->ownerUserId) {
            throw new DomainViolation('account_access_denied', 'Posting accounts must belong to the authenticated owner.');
        }
        if ($financial->externalNumber?->value !== $entry->financialAccountNumber) {
            throw new DomainViolation('account_changed', 'The financial account no longer matches the prepared operation.');
        }
        $amount = $entry->journal->postings[0]->amountMinor;
        $expected = JournalEntry::forMovement($financial, $counterpart, $type, Money::positiveFromDecimal($amount),
            new PostingDate($entry->journal->transactionDate), $entry->journal->description);
        if ($expected->toData() != $entry->journal) {
            throw new DomainViolation('invalid_prepared_posting', 'The prepared postings do not match the balanced movement.');
        }
        $row = (new CsvRowCanonicalizer)->canonicalize(new CsvRowData(
            $entry->journal->transactionDate, $entry->originalDescription, $amount,
            $type === MovementType::Income ? 'Receita' : 'Despesa',
        ));
        if ($row->sourceRowHash !== $entry->sourceRowHash || $row->canonicalRecord !== $entry->canonicalRecord
            || $row->description !== $entry->journal->description || $row->accountNumber !== $entry->financialAccountNumber) {
            throw new DomainViolation('invalid_posting_identity', 'The canonical identity does not match the operation.');
        }
    }
}
