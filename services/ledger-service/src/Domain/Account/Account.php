<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Account\Event\AccountOpened;
use App\Domain\Account\Exception\AccountHasBalance;
use App\Domain\Account\Exception\AccountNotActive;
use App\Domain\Account\Exception\InsufficientFunds;
use App\Domain\Ledger\Direction;
use App\Domain\Shared\Exception\CurrencyMismatch;
use App\Domain\Shared\RecordsEvents;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * A ledger account. The balance is a cache of the account's postings, kept in
 * step under a row lock; postings remain the source of truth.
 *
 * Sign convention: CREDIT increases the balance and DEBIT decreases it, for
 * every account type. Settlement accounts therefore hold the negative of the
 * money in custody, and balances within one currency always sum to zero.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
#[ORM\Index(name: 'idx_accounts_type_currency', columns: ['type', 'currency'])]
class Account
{
    use RecordsEvents;

    #[ORM\Column(type: Types::BIGINT)]
    private int $balanceMinor = 0;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 20, enumType: AccountType::class)]
        private AccountType $type,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column(length: 20, enumType: AccountStatus::class)]
        private AccountStatus $status,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public static function openCustomer(Uuid $id, string $name, Currency $currency, \DateTimeImmutable $now): self
    {
        $account = new self($id, $name, AccountType::CUSTOMER, $currency->getCode(), AccountStatus::ACTIVE, $now);
        $account->record(new AccountOpened($id, $name, AccountType::CUSTOMER, $currency->getCode(), $now));

        return $account;
    }

    public static function openSettlement(Uuid $id, Currency $currency, \DateTimeImmutable $now): self
    {
        $name = \sprintf('Settlement %s', $currency->getCode());
        $account = new self($id, $name, AccountType::SETTLEMENT, $currency->getCode(), AccountStatus::ACTIVE, $now);
        $account->record(new AccountOpened($id, $name, AccountType::SETTLEMENT, $currency->getCode(), $now));

        return $account;
    }

    /**
     * Applies one side of a journal entry and returns the resulting balance.
     */
    public function applyPosting(Direction $direction, Money $amount, \DateTimeImmutable $now): Money
    {
        if (!$amount->getCurrency()->equals($this->currency())) {
            throw CurrencyMismatch::between($this->currency(), $amount->getCurrency());
        }
        if (AccountStatus::ACTIVE !== $this->status) {
            throw AccountNotActive::forAccount($this->id, $this->status);
        }

        $balance = Direction::CREDIT === $direction
            ? $this->balance()->add($amount)
            : $this->balance()->subtract($amount);

        if (AccountType::CUSTOMER === $this->type && $balance->isNegative()) {
            throw InsufficientFunds::forAccount($this->id, $this->balance(), $amount);
        }

        $this->balanceMinor = (int) $balance->getAmount();
        $this->updatedAt = $now;

        return $balance;
    }

    public function freeze(\DateTimeImmutable $now): void
    {
        if (AccountStatus::CLOSED === $this->status) {
            throw AccountNotActive::forAccount($this->id, $this->status);
        }
        $this->status = AccountStatus::FROZEN;
        $this->updatedAt = $now;
    }

    public function close(\DateTimeImmutable $now): void
    {
        if (!$this->balance()->isZero()) {
            throw AccountHasBalance::forAccount($this->id, $this->balance());
        }
        $this->status = AccountStatus::CLOSED;
        $this->updatedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): AccountType
    {
        return $this->type;
    }

    public function currency(): Currency
    {
        \assert('' !== $this->currency);

        return new Currency($this->currency);
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function balance(): Money
    {
        return new Money($this->balanceMinor, $this->currency());
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
