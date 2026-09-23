<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\EntrySide;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\MovementType;
use App\Domain\Accounting\Posting;
use App\Domain\Accounting\PostingDate;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;

final class JournalEntryTest extends TestCase
{
    public function test_income_debits_the_financial_account_and_credits_revenue(): void
    {
        $entry = JournalEntry::forMovement(Accounts::financial(), Accounts::revenue(), MovementType::Income, Money::fromMinor(12345), new PostingDate('2026-08-16'), 'Serviços');
        $data = $entry->toData();
        self::assertSame('7', $data->ownerUserId);
        self::assertCount(2, $data->postings);
        self::assertSame(['42', '7001'], array_column($data->postings, 'accountId'));
        self::assertSame(['debit', 'credit'], array_column($data->postings, 'side'));
        self::assertSame(['12345', '12345'], array_column($data->postings, 'amountMinor'));
    }

    public function test_expense_debits_expenses_and_credits_the_financial_account(): void
    {
        $entry = JournalEntry::forMovement(Accounts::financial(), Accounts::expense(), MovementType::Expense, Money::fromMinor(494618), new PostingDate('2026-08-16'), 'Serviços de Limpeza');
        self::assertSame(['7002', '42'], array_column($entry->toData()->postings, 'accountId'));
        self::assertSame(['debit', 'credit'], array_column($entry->toData()->postings, 'side'));
        self::assertSame(['494618', '494618'], array_column($entry->toData()->postings, 'amountMinor'));
    }

    public function test_unbalanced_entry_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        new JournalEntry(new PostingDate('2026-08-16'), 'Unbalanced', [
            new Posting(Accounts::financial(), EntrySide::Debit, Money::fromMinor(100)),
            new Posting(Accounts::revenue(), EntrySide::Credit, Money::fromMinor(99)),
        ]);
    }

    public function test_a_single_posting_cannot_form_an_entry(): void
    {
        $this->expectException(DomainViolation::class);
        new JournalEntry(new PostingDate('2026-08-16'), 'One side', [new Posting(Accounts::financial(), EntrySide::Debit, Money::fromMinor(100))]);
    }

    public function test_balanced_split_entry_is_valid(): void
    {
        $entry = new JournalEntry(new PostingDate('2026-08-16'), 'Split', [
            new Posting(Accounts::financial(), EntrySide::Debit, Money::fromMinor(30)),
            new Posting(Accounts::expense(), EntrySide::Debit, Money::fromMinor(70)),
            new Posting(Accounts::revenue(), EntrySide::Credit, Money::fromMinor(100)),
        ]);
        self::assertCount(3, $entry->toData()->postings);
    }

    public function test_total_overflow_is_rejected_even_when_both_sides_would_balance(): void
    {
        $this->expectException(DomainViolation::class);
        new JournalEntry(new PostingDate('2026-08-16'), 'Overflow', [
            new Posting(Accounts::financial(), EntrySide::Debit, Money::fromMinor(PHP_INT_MAX)),
            new Posting(Accounts::expense(), EntrySide::Debit, Money::fromMinor(1)),
            new Posting(Accounts::revenue(), EntrySide::Credit, Money::fromMinor(PHP_INT_MAX)),
            new Posting(Accounts::revenue(), EntrySide::Credit, Money::fromMinor(1)),
        ]);
    }

    public function test_cross_owner_entry_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        JournalEntry::forMovement(Accounts::financial(), Account::fromData(Accounts::data()[5]), MovementType::Income, Money::fromMinor(100), new PostingDate('2026-08-16'), 'Foreign owner');
    }

    public function test_wrong_technical_account_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        JournalEntry::forMovement(Accounts::financial(), Accounts::expense(), MovementType::Income, Money::fromMinor(100), new PostingDate('2026-08-16'), 'Wrong kind');
    }

    public function test_same_identity_for_financial_and_technical_accounts_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        $duplicate = Account::fromData(new AccountData('42', '7', null, 'revenue'));
        JournalEntry::forMovement(Accounts::financial(), $duplicate, MovementType::Income, Money::fromMinor(100), new PostingDate('2026-08-16'), 'Conflicting identity');
    }

    /** @return iterable<string, array{int}> */
    public static function nonPositive(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('nonPositive')]
    public function test_posting_amounts_are_strictly_positive(int $minor): void
    {
        $this->expectException(DomainViolation::class);
        new Posting(Accounts::financial(), EntrySide::Debit, Money::fromMinor($minor));
    }

    public function test_inactive_account_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        new Posting(Account::fromData(Accounts::data()[2]), EntrySide::Credit, Money::fromMinor(1));
    }

    public function test_account_balance_uses_its_nature_and_allows_negative_assets(): void
    {
        self::assertSame('-50', AccountKind::Asset->balance(Money::fromMinor(100), Money::fromMinor(150))->toDecimal());
        self::assertSame('50', AccountKind::Revenue->balance(Money::fromMinor(100), Money::fromMinor(150))->toDecimal());
        self::assertSame('100', AccountKind::Expense->balance(Money::fromMinor(150), Money::fromMinor(50))->toDecimal());
    }

    public function test_entity_converts_to_a_pure_data_object(): void
    {
        $data = Accounts::financial()->toData();
        self::assertEquals(Accounts::data()[0], $data);
        self::assertEquals(Accounts::financial(), Account::fromData($data));
    }
}
