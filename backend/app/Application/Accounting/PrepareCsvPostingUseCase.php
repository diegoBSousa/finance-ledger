<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Application\Accounting\Data\PrepareCsvPostingRequest;
use App\Application\Accounting\Data\PrepareCsvPostingResponse;
use App\Application\Imports\CsvRowCanonicalizer;
use App\Domain\Accounting\Account;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\MovementType;
use App\Domain\Accounting\PostingDate;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;

final readonly class PrepareCsvPostingUseCase
{
    public function __construct(private AccountRepository $accounts, private CsvRowCanonicalizer $canonicalizer) {}

    public function execute(PrepareCsvPostingRequest $request): PrepareCsvPostingResponse
    {
        $actor = (string) DecimalInteger::positive($request->actorUserId);
        $row = $this->canonicalizer->canonicalize($request->row);
        $financialData = $this->accounts->findFinancialByNumber($row->accountNumber)
            ?? throw new DomainViolation('account_not_found', 'The financial account does not exist.');
        $financial = Account::fromData($financialData);

        if ($financial->kind !== AccountKind::Asset || $financial->externalNumber?->value !== $row->accountNumber) {
            throw new DomainViolation('repository_contract_violation', 'The resolved account does not match the requested external number.');
        }

        if ($financial->ownerUserId !== $actor) {
            throw new DomainViolation('account_access_denied', 'The actor cannot post to another owner\'s account.');
        }

        if (! $financial->active) {
            throw new DomainViolation('inactive_account', 'The financial account is inactive.');
        }

        $type = MovementType::from($row->movementType);
        $technicalData = $this->accounts->findTechnical($financial->ownerUserId, $type->counterpartKind())
            ?? throw new DomainViolation('counterpart_not_found', 'The owner\'s technical account does not exist.');
        $technical = Account::fromData($technicalData);

        $entry = JournalEntry::forMovement(
            $financial,
            $technical,
            $type,
            Money::positiveFromDecimal($row->amountMinor),
            new PostingDate($row->date),
            $row->description,
        );

        return new PrepareCsvPostingResponse(
            $actor,
            $financial->id,
            $row->accountNumber,
            $row->movementType,
            $row->sourceRowHash,
            $row->canonicalRecord,
            $request->row->description,
            $entry->toData(),
        );
    }
}
