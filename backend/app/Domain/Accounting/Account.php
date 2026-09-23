<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\Currency;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;

final readonly class Account
{
    public string $id;

    public string $ownerUserId;

    public function __construct(
        string $id,
        string $ownerUserId,
        public ?AccountNumber $externalNumber,
        public AccountKind $kind,
        public Currency $currency = Currency::BRL,
        public bool $active = true,
    ) {
        $this->id = (string) DecimalInteger::positive($id);
        $this->ownerUserId = (string) DecimalInteger::positive($ownerUserId);

        if (($kind === AccountKind::Asset) !== ($externalNumber !== null)) {
            throw new DomainViolation('invalid_account_role', 'Financial assets require a number; technical accounts must not have one.');
        }
    }

    public static function fromData(AccountData $data): self
    {
        return new self(
            $data->id,
            $data->ownerUserId,
            $data->externalNumber === null ? null : new AccountNumber($data->externalNumber),
            AccountKind::tryFrom($data->kind)
                ?? throw new DomainViolation('invalid_account_kind', 'Unknown account kind.'),
            Currency::fromCode($data->currency),
            $data->active,
        );
    }

    public function toData(): AccountData
    {
        return new AccountData($this->id, $this->ownerUserId, $this->externalNumber?->value, $this->kind->value, $this->currency->value, $this->active);
    }
}
