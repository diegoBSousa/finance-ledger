<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Application\Accounting\Data\PrepareCsvPostingRequest;
use App\Application\Accounting\PrepareCsvPostingUseCase;
use App\Application\Imports\CsvRowCanonicalizer;
use App\Application\Imports\Data\CsvRowData;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Doubles\InMemoryAccountRepository;

final class PrepareCsvPostingUseCaseTest extends TestCase
{
    private function request(string $actor = '7', string $description = 'Serviços de Limpeza #682', string $amount = '494618'): PrepareCsvPostingRequest
    {
        return new PrepareCsvPostingRequest($actor, new CsvRowData('2026-08-16', $description, $amount, 'Despesa'));
    }

    public function test_external_number_resolves_to_its_account_and_owner(): void
    {
        $accounts = new InMemoryAccountRepository(Accounts::data());
        $response = (new PrepareCsvPostingUseCase($accounts, new CsvRowCanonicalizer))->execute($this->request());
        self::assertSame('42', $response->financialAccountId);
        self::assertSame('682', $response->financialAccountNumber);
        self::assertSame('7', $response->journal->ownerUserId);
        self::assertSame('7', $response->uploadedByUserId);
        self::assertSame(['7002', '42'], array_column($response->journal->postings, 'accountId'));
        self::assertSame('d4862cdd783f053103511bf4f49bfb5438fce54b40bbabc58163b424e1136a8b', $response->sourceRowHash);
        self::assertSame(['financial:682', 'technical:7:expense'], $accounts->lookups);
    }

    public function test_amounts_stay_strings_when_the_response_is_serialized(): void
    {
        $response = (new PrepareCsvPostingUseCase(new InMemoryAccountRepository(Accounts::data()), new CsvRowCanonicalizer))
            ->execute($this->request(amount: '9007199254740993'));
        self::assertStringContainsString('"amountMinor":"9007199254740993"', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function test_foreign_account_is_rejected_before_technical_account_lookup(): void
    {
        $accounts = new InMemoryAccountRepository(Accounts::data());

        try {
            (new PrepareCsvPostingUseCase($accounts, new CsvRowCanonicalizer))->execute($this->request(description: 'Compra #683'));
            self::fail('A foreign account was accepted.');
        } catch (DomainViolation $exception) {
            self::assertSame('account_access_denied', $exception->reason);
            self::assertSame(['financial:683'], $accounts->lookups);
        }
    }

    public function test_missing_account_is_not_assigned_to_the_uploader(): void
    {
        $accounts = new InMemoryAccountRepository(Accounts::data());

        try {
            (new PrepareCsvPostingUseCase($accounts, new CsvRowCanonicalizer))->execute($this->request(description: 'Compra #999'));
            self::fail('A missing account was accepted.');
        } catch (DomainViolation $exception) {
            self::assertSame('account_not_found', $exception->reason);
            self::assertSame(['financial:999'], $accounts->lookups);
        }
    }

    public function test_inactive_financial_account_is_rejected(): void
    {
        $this->expectException(DomainViolation::class);
        (new PrepareCsvPostingUseCase(new InMemoryAccountRepository(Accounts::data()), new CsvRowCanonicalizer))
            ->execute($this->request(description: 'Compra #684'));
    }

    public function test_missing_technical_account_is_not_created_silently(): void
    {
        $this->expectException(DomainViolation::class);
        (new PrepareCsvPostingUseCase(new InMemoryAccountRepository([Accounts::data()[0]]), new CsvRowCanonicalizer))->execute($this->request());
    }

    public function test_invalid_csv_is_rejected_before_any_repository_lookup(): void
    {
        $accounts = new InMemoryAccountRepository(Accounts::data());

        try {
            (new PrepareCsvPostingUseCase($accounts, new CsvRowCanonicalizer))->execute($this->request(amount: '1e3'));
            self::fail('An invalid row was accepted.');
        } catch (DomainViolation) {
            self::assertSame([], $accounts->lookups);
        }
    }

    public function test_broken_repository_cannot_substitute_the_target_account(): void
    {
        $accounts = $this->createMock(AccountRepository::class);
        $accounts->method('findFinancialByNumber')->willReturn(new AccountData('45', '7', '999', 'asset'));
        $accounts->expects(self::never())->method('findTechnical');
        $this->expectException(DomainViolation::class);
        (new PrepareCsvPostingUseCase($accounts, new CsvRowCanonicalizer))->execute($this->request());
    }

    public function test_broken_repository_cannot_mix_technical_owners(): void
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findFinancialByNumber')->willReturn(Accounts::data()[0]);
        $accounts->method('findTechnical')->willReturn(Accounts::data()[6]);
        $this->expectException(DomainViolation::class);
        (new PrepareCsvPostingUseCase($accounts, new CsvRowCanonicalizer))->execute($this->request());
    }

    public function test_repeated_preparation_is_deterministic_and_preserves_raw_description_for_audit(): void
    {
        $useCase = new PrepareCsvPostingUseCase(new InMemoryAccountRepository(Accounts::data()), new CsvRowCanonicalizer);
        $request = $this->request(description: '  Serviços de Limpeza #00682  ');
        $first = $useCase->execute($request);
        self::assertEquals($first, $useCase->execute($request));
        self::assertSame($request->row->description, $first->originalDescription);
        self::assertSame('Serviços de Limpeza', $first->journal->description);
    }
}
