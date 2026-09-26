<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Application\Port\DuplicateRecord;
use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\SupportedCurrencies;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferKind;
use App\Domain\Transfer\TransferRepository;
use App\Tests\Support\IntegrationTestCase;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final class DoctrineTransactionManagerTest extends IntegrationTestCase
{
    public function testCommitsFlushedChanges(): void
    {
        $account = $this->newAccount();

        $this->service(TransactionManager::class)->transactional(function () use ($account): void {
            $this->service(AccountRepository::class)->add($account);
        });

        self::assertSame(1, $this->countAccounts($account->id()));
    }

    public function testReturnsTheOperationResult(): void
    {
        self::assertSame(42, $this->service(TransactionManager::class)->transactional(static fn(): int => 42));
    }

    public function testRollsBackAndStaysUsableAfterAnException(): void
    {
        $account = $this->newAccount();

        $caught = null;
        try {
            $this->service(TransactionManager::class)->transactional(function () use ($account): void {
                $this->service(AccountRepository::class)->add($account);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('boom', $caught->getMessage());
        self::assertSame(0, $this->countAccounts($account->id()));

        $next = $this->newAccount();
        $this->service(TransactionManager::class)->transactional(fn() => $this->service(AccountRepository::class)->add($next));
        self::assertSame(1, $this->countAccounts($next->id()));
    }

    public function testTranslatesUniqueViolationAndRecoversEntityManager(): void
    {
        $transactions = $this->service(TransactionManager::class);
        $transfers = $this->service(TransferRepository::class);
        $transactions->transactional(fn() => $transfers->add($this->transfer('dup-key')));

        try {
            $transactions->transactional(fn() => $transfers->add($this->transfer('dup-key')));
            self::fail('Expected DuplicateRecord');
        } catch (DuplicateRecord) {
        }

        self::assertNotNull($transfers->findByIdempotencyKey('dup-key'));
        $next = $this->newAccount();
        $transactions->transactional(fn() => $this->service(AccountRepository::class)->add($next));
        self::assertSame(1, $this->countAccounts($next->id()));
    }

    private function newAccount(): Account
    {
        return Account::openCustomer(Uuid::v7(), 'Tx', SupportedCurrencies::get('USD'), new \DateTimeImmutable());
    }

    private function transfer(string $key): Transfer
    {
        return Transfer::complete(Uuid::v7(), TransferKind::INTERNAL, Uuid::v7(), Uuid::v7(), new Money(1, SupportedCurrencies::get('USD')), null, $key, 'hash', Uuid::v7(), new \DateTimeImmutable());
    }

    private function countAccounts(Uuid $id): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM accounts WHERE id = :id', ['id' => $id->toRfc4122()]);
    }
}
