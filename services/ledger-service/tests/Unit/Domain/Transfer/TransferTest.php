<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer;

use App\Domain\Shared\Exception\InvalidAmount;
use App\Domain\Shared\SupportedCurrencies;
use App\Domain\Transfer\Event\DepositRecorded;
use App\Domain\Transfer\Event\TransferCompleted;
use App\Domain\Transfer\Event\WithdrawalRecorded;
use App\Domain\Transfer\Exception\SameAccountTransfer;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferKind;
use App\Domain\Transfer\TransferStatus;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TransferTest extends TestCase
{
    public function testCompletesAndRecordsTransferCompletedWithContractPayload(): void
    {
        $id = Uuid::v7();
        $source = Uuid::v7();
        $destination = Uuid::v7();
        $entry = Uuid::v7();
        $at = new \DateTimeImmutable('2026-09-24 10:00:00');

        $transfer = Transfer::complete($id, TransferKind::INTERNAL, $source, $destination, new Money(12550, SupportedCurrencies::get('USD')), 'Dinner', 'key-1', str_repeat('a', 64), $entry, $at);

        self::assertSame(TransferStatus::COMPLETED, $transfer->status());
        self::assertTrue($transfer->amount()->equals(new Money(12550, SupportedCurrencies::get('USD'))));
        self::assertTrue($transfer->matchesRequest(str_repeat('a', 64)));
        self::assertFalse($transfer->matchesRequest(str_repeat('b', 64)));

        $events = $transfer->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TransferCompleted::class, $events[0]);
        self::assertSame([
            'transferId' => $id->toRfc4122(),
            'journalEntryId' => $entry->toRfc4122(),
            'sourceAccountId' => $source->toRfc4122(),
            'destinationAccountId' => $destination->toRfc4122(),
            'amount' => '125.50',
            'amountMinor' => 12550,
            'currency' => 'USD',
            'description' => 'Dinner',
            'completedAt' => '2026-09-24T10:00:00.000+00:00',
        ], $events[0]->payload());
    }

    /** @return iterable<string, array{TransferKind, class-string, string}> */
    public static function kinds(): iterable
    {
        yield 'internal' => [TransferKind::INTERNAL, TransferCompleted::class, 'ledger.transfer_completed'];
        yield 'deposit' => [TransferKind::DEPOSIT, DepositRecorded::class, 'ledger.deposit_recorded'];
        yield 'withdrawal' => [TransferKind::WITHDRAWAL, WithdrawalRecorded::class, 'ledger.withdrawal_recorded'];
    }

    /** @param class-string $eventClass */
    #[DataProvider('kinds')]
    public function testRecordsEventMatchingKind(TransferKind $kind, string $eventClass, string $eventName): void
    {
        $transfer = Transfer::complete(Uuid::v7(), $kind, Uuid::v7(), Uuid::v7(), new Money(1, SupportedCurrencies::get('USD')), null, 'k', 'h', Uuid::v7(), new \DateTimeImmutable());

        $event = $transfer->pullEvents()[0];
        self::assertInstanceOf($eventClass, $event);
        self::assertSame($eventName, $event->eventName());
        self::assertTrue($transfer->id()->equals($event->aggregateId()));
    }

    public function testRejectsSameSourceAndDestination(): void
    {
        $account = Uuid::v7();

        $this->expectException(SameAccountTransfer::class);

        Transfer::complete(Uuid::v7(), TransferKind::INTERNAL, $account, Uuid::fromString($account->toRfc4122()), new Money(1, SupportedCurrencies::get('USD')), null, 'k', 'h', Uuid::v7(), new \DateTimeImmutable());
    }

    public function testRejectsNonPositiveAmount(): void
    {
        $this->expectException(InvalidAmount::class);

        Transfer::complete(Uuid::v7(), TransferKind::INTERNAL, Uuid::v7(), Uuid::v7(), new Money(0, SupportedCurrencies::get('USD')), null, 'k', 'h', Uuid::v7(), new \DateTimeImmutable());
    }
}
