<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Account;

use App\Domain\Account\Account;
use App\Domain\Account\AccountStatus;
use App\Domain\Account\AccountType;
use App\Domain\Account\Event\AccountOpened;
use App\Domain\Account\Exception\AccountHasBalance;
use App\Domain\Account\Exception\AccountNotActive;
use App\Domain\Account\Exception\InsufficientFunds;
use App\Domain\Ledger\Direction;
use App\Domain\Shared\Exception\CurrencyMismatch;
use App\Domain\Shared\SupportedCurrencies;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AccountTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-24 10:00:00');
    }

    public function testOpeningACustomerAccountStartsActiveWithZeroBalanceAndRecordsEvent(): void
    {
        $id = Uuid::v7();
        $account = Account::openCustomer($id, 'Alice', SupportedCurrencies::get('USD'), $this->now);

        self::assertTrue($id->equals($account->id()));
        self::assertSame(AccountType::CUSTOMER, $account->type());
        self::assertSame(AccountStatus::ACTIVE, $account->status());
        self::assertTrue($account->balance()->equals($this->usd(0)));

        $events = $account->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AccountOpened::class, $events[0]);
        self::assertSame('ledger.account_opened', $events[0]->eventName());
        self::assertSame(
            ['accountId' => $id->toRfc4122(), 'name' => 'Alice', 'type' => 'customer', 'currency' => 'USD', 'openedAt' => '2026-09-24T10:00:00.000+00:00'],
            $events[0]->payload(),
        );
    }

    public function testCreditIncreasesAndDebitDecreasesBalance(): void
    {
        $account = $this->customer();

        self::assertTrue($account->applyPosting(Direction::CREDIT, $this->usd(1000), $this->now)->equals($this->usd(1000)));
        self::assertTrue($account->applyPosting(Direction::DEBIT, $this->usd(400), $this->now)->equals($this->usd(600)));
        self::assertTrue($account->balance()->equals($this->usd(600)));
    }

    public function testCustomerAccountCannotGoNegative(): void
    {
        $account = $this->customer();
        $account->applyPosting(Direction::CREDIT, $this->usd(100), $this->now);

        try {
            $account->applyPosting(Direction::DEBIT, $this->usd(101), $this->now);
            self::fail('Expected InsufficientFunds');
        } catch (InsufficientFunds $e) {
            self::assertStringContainsString('balance 1.00 USD, requested 1.01 USD', $e->getMessage());
        }
        self::assertTrue($account->balance()->equals($this->usd(100)), 'balance must be unchanged');
    }

    public function testSettlementAccountMayGoNegative(): void
    {
        $settlement = Account::openSettlement(Uuid::v7(), SupportedCurrencies::get('USD'), $this->now);

        $balance = $settlement->applyPosting(Direction::DEBIT, $this->usd(500), $this->now);

        self::assertTrue($balance->equals($this->usd(-500)));
        self::assertSame(AccountType::SETTLEMENT, $settlement->type());
    }

    public function testRejectsPostingInAnotherCurrency(): void
    {
        $this->expectException(CurrencyMismatch::class);

        $this->customer()->applyPosting(Direction::CREDIT, new Money(100, SupportedCurrencies::get('EUR')), $this->now);
    }

    public function testFrozenAccountRejectsPostings(): void
    {
        $account = $this->customer();
        $account->freeze($this->now);

        $this->expectException(AccountNotActive::class);

        $account->applyPosting(Direction::CREDIT, $this->usd(100), $this->now);
    }

    public function testCloseRequiresZeroBalance(): void
    {
        $account = $this->customer();
        $account->applyPosting(Direction::CREDIT, $this->usd(1), $this->now);

        $this->expectException(AccountHasBalance::class);

        $account->close($this->now);
    }

    public function testClosedAccountCannotBeFrozen(): void
    {
        $account = $this->customer();
        $account->close($this->now);
        self::assertSame(AccountStatus::CLOSED, $account->status());

        $this->expectException(AccountNotActive::class);

        $account->freeze($this->now);
    }

    private function customer(): Account
    {
        return Account::openCustomer(Uuid::v7(), 'Alice', SupportedCurrencies::get('USD'), $this->now);
    }

    private function usd(int $minor): Money
    {
        return new Money($minor, SupportedCurrencies::get('USD'));
    }
}
