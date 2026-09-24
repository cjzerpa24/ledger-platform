<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\AccountType;
use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Ledger\Direction;
use App\Domain\Shared\SupportedCurrencies;
use App\Tests\Support\IntegrationTestCase;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

final class DoctrineAccountRepositoryTest extends IntegrationTestCase
{
    public function testPersistsAndReloadsAccount(): void
    {
        $repository = $this->service(AccountRepository::class);
        $account = Account::openCustomer(Uuid::v7(), 'Alice', SupportedCurrencies::get('TZS'), new \DateTimeImmutable());
        $account->applyPosting(Direction::CREDIT, new Money(123456, SupportedCurrencies::get('TZS')), new \DateTimeImmutable());
        $repository->add($account);
        $this->em()->flush();
        $this->em()->clear();

        $reloaded = $repository->get($account->id());

        self::assertNotSame($account, $reloaded);
        self::assertSame('Alice', $reloaded->name());
        self::assertSame('TZS', $reloaded->currency()->getCode());
        self::assertSame('123456', $reloaded->balance()->getAmount());
    }

    public function testGetThrowsForUnknownAccount(): void
    {
        $this->expectException(AccountNotFound::class);

        $this->service(AccountRepository::class)->get(Uuid::v7());
    }

    public function testGetForUpdateRefreshesStaleInMemoryBalance(): void
    {
        $repository = $this->service(AccountRepository::class);
        $account = Account::openCustomer(Uuid::v7(), 'Bob', SupportedCurrencies::get('USD'), new \DateTimeImmutable());
        $repository->add($account);
        $this->em()->flush();

        $this->connection()->executeStatement(
            'UPDATE accounts SET balance_minor = 999 WHERE id = :id',
            ['id' => $account->id()->toRfc4122()],
        );

        $locked = $this->service(TransactionManager::class)->transactional(
            fn(): Account => $repository->getForUpdate($account->id()),
        );

        self::assertSame('999', $locked->balance()->getAmount());
    }

    /** @return iterable<string, array{string}> */
    public static function supportedCurrencies(): iterable
    {
        foreach (SupportedCurrencies::codes() as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('supportedCurrencies')]
    public function testEverySupportedCurrencyHasASettlementAccount(string $code): void
    {
        $settlement = $this->service(AccountRepository::class)->getSettlement(SupportedCurrencies::get($code));

        self::assertSame(AccountType::SETTLEMENT, $settlement->type());
        self::assertSame($code, $settlement->currency()->getCode());
    }
}
