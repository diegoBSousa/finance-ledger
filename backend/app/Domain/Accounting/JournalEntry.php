<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Utf8Text;

final readonly class JournalEntry
{
    public string $ownerUserId;

    public string $description;

    /** @var list<Posting> */
    public array $postings;

    /** @param array<array-key, Posting> $postings */
    public function __construct(public PostingDate $date, string $description, array $postings)
    {
        if (! array_is_list($postings) || count($postings) < 2) {
            throw new DomainViolation('invalid_postings', 'An entry requires an ordered list of at least two postings.');
        }

        $this->postings = $postings;

        $this->description = Utf8Text::canonical($description);

        if ($this->description === '') {
            throw new DomainViolation('empty_description', 'An entry requires a description.');
        }

        $this->ownerUserId = $postings[0]->account->ownerUserId;
        $debits = Money::fromMinor(0);
        $credits = Money::fromMinor(0);

        foreach ($postings as $posting) {
            if ($posting->account->ownerUserId !== $this->ownerUserId) {
                throw new DomainViolation('owner_mismatch', 'All accounts must belong to the same owner.');
            }

            if ($posting->side === EntrySide::Debit) {
                $debits = $debits->add($posting->amount);
            } else {
                $credits = $credits->add($posting->amount);
            }
        }

        if ($debits->minor !== $credits->minor) {
            throw new DomainViolation('unbalanced_entry', 'Debit and credit totals must be equal.');
        }
    }

    public static function forMovement(
        Account $financial,
        Account $counterpart,
        MovementType $type,
        Money $amount,
        PostingDate $date,
        string $description,
    ): self {
        if ($financial->kind !== AccountKind::Asset || $counterpart->kind !== $type->counterpartKind()) {
            throw new DomainViolation('invalid_counterpart', 'The movement requires a financial asset and its matching technical account.');
        }

        if ($financial->id === $counterpart->id) {
            throw new DomainViolation('same_account', 'Financial and technical accounts must have different identities.');
        }

        [$debit, $credit] = $type === MovementType::Income
            ? [$financial, $counterpart]
            : [$counterpart, $financial];

        return new self($date, $description, [
            new Posting($debit, EntrySide::Debit, $amount),
            new Posting($credit, EntrySide::Credit, $amount),
        ]);
    }

    public function toData(): JournalEntryData
    {
        return new JournalEntryData(
            $this->ownerUserId,
            $this->date->value,
            $this->description,
            array_map(fn (Posting $posting) => $posting->toData(), $this->postings),
        );
    }
}
