<?php

declare(strict_types=1);

namespace App\Application\Accounting\Data;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;

final readonly class PostJournalData
{
    public function __construct(
        public string $financialAccountId,
        public string $financialAccountNumber,
        public string $movementType,
        public string $sourceRowHash,
        public string $canonicalRecord,
        public string $originalDescription,
        public JournalEntryData $journal,
    ) {
        DecimalInteger::positive($financialAccountId);
        DecimalInteger::positive($financialAccountNumber);
        if (array_keys($journal->postings) !== [0, 1]) {
            throw new DomainViolation('invalid_postings', 'A CSV operation requires exactly two postings.');
        }
        foreach ($journal->postings as $posting) {
            if ((string) DecimalInteger::positive($posting->accountId) !== $posting->accountId) {
                throw new DomainViolation('invalid_account_id', 'Account IDs must be canonical decimal strings.');
            }
        }
        foreach ([$canonicalRecord, $originalDescription, $journal->description] as $text) {
            if (strlen($text) > 65535) {
                throw new DomainViolation('posting_field_too_large', 'Posting text exceeds 65535 bytes.');
            }
        }
    }

    public static function fromPrepared(PrepareCsvPostingResponse $prepared): self
    {
        return new self(
            $prepared->financialAccountId, $prepared->financialAccountNumber,
            $prepared->movementType, $prepared->sourceRowHash, $prepared->canonicalRecord,
            $prepared->originalDescription, $prepared->journal,
        );
    }
}
