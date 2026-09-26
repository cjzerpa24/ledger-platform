<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Port\TransactionManager;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\Exception\AccountNotActive;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Uid\Uuid;

final class PostTransferServiceTest extends IntegrationTestCase
{
    public function testTransferWritesBalancedEntryBalancesAndOneEvent(): void
    {
        $alice = $this->open('Alice');
        $bob = $this->open('Bob');
        $this->deposit($alice, '100.00');

        $result = $this->service(TransferMoneyHandler::class)->handle(
            new TransferMoneyCommand(Uuid::fromString($alice), Uuid::fromString($bob), '40.00', 'Rent', 'transfer-1'),
        );

        self::assertFalse($result->replayed);
        self::assertSame('40.00', $result->transfer->amount);

        $postings = $this->connection()->fetchAllAssociative(
            'SELECT account_id, direction, amount_minor, balance_after_minor FROM postings WHERE journal_entry_id = :id ORDER BY direction',
            ['id' => $result->transfer->journalEntryId],
        );
        self::assertEquals([
            ['account_id' => $bob, 'direction' => 'credit', 'amount_minor' => 4000, 'balance_after_minor' => 4000],
            ['account_id' => $alice, 'direction' => 'debit', 'amount_minor' => 4000, 'balance_after_minor' => 6000],
        ], $postings);

        self::assertSame(1, $this->fetchInt(
            "SELECT COUNT(*) FROM outbox_messages WHERE aggregate_id = :id AND event_name = 'ledger.transfer_completed'",
            ['id' => $result->transfer->id],
        ));
    }

    public function testFrozenAccountRejectsPostingAndPersistsNothing(): void
    {
        $alice = $this->open('Alice');
        $bob = $this->open('Bob');
        $this->deposit($alice, '100.00');
        $this->service(TransactionManager::class)->transactional(function () use ($bob): void {
            $this->service(AccountRepository::class)->get(Uuid::fromString($bob))->freeze(new \DateTimeImmutable());
        });
        $transfersBefore = $this->fetchInt('SELECT COUNT(*) FROM transfers');
        $outboxBefore = $this->fetchInt('SELECT COUNT(*) FROM outbox_messages');
        $postingsBefore = $this->fetchInt('SELECT COUNT(*) FROM postings');

        try {
            $this->service(TransferMoneyHandler::class)->handle(
                new TransferMoneyCommand(Uuid::fromString($alice), Uuid::fromString($bob), '10.00', null, 'frozen-1'),
            );
            self::fail('Expected AccountNotActive');
        } catch (AccountNotActive) {
        }

        self::assertSame($transfersBefore, $this->fetchInt('SELECT COUNT(*) FROM transfers'));
        self::assertSame($outboxBefore, $this->fetchInt('SELECT COUNT(*) FROM outbox_messages'));
        self::assertSame($postingsBefore, $this->fetchInt('SELECT COUNT(*) FROM postings'));
        self::assertSame(10000, $this->fetchInt('SELECT balance_minor FROM accounts WHERE id = :id', ['id' => $alice]));
    }

    private function open(string $name): string
    {
        return $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand($name, 'USD'))->id;
    }

    private function deposit(string $accountId, string $amount): void
    {
        $this->service(RecordDepositHandler::class)->handle(
            new RecordDepositCommand(Uuid::fromString($accountId), $amount, null, 'deposit-' . $accountId),
        );
    }
}
