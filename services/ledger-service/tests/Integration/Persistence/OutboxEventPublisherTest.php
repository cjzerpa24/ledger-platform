<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Application\Port\EventPublisher;
use App\Application\Port\TransactionManager;
use App\Domain\Account\AccountType;
use App\Domain\Account\Event\AccountOpened;
use App\Infrastructure\Persistence\Doctrine\Outbox\OutboxMessage;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Uid\Uuid;

final class OutboxEventPublisherTest extends IntegrationTestCase
{
    public function testEventIsStoredWithTheTransaction(): void
    {
        $event = $this->event();

        $this->service(TransactionManager::class)->transactional(fn() => $this->service(EventPublisher::class)->publish($event));
        $this->em()->clear();

        $message = $this->em()->getRepository(OutboxMessage::class)->findOneBy(['aggregateId' => $event->accountId]);
        self::assertInstanceOf(OutboxMessage::class, $message);
        self::assertSame('ledger.account_opened', $message->eventName());
        // JSONB normalises key order; the contract is about keys and values.
        self::assertEquals($event->payload(), $message->payload());
        self::assertNull($message->publishedAt());
        self::assertSame(0, $message->attempts());

        $envelope = $message->toEnvelope();
        self::assertSame($message->id()->toRfc4122(), $envelope['eventId']);
        self::assertSame(1, $envelope['eventVersion']);
        self::assertSame('2026-09-24T10:00:00.000+00:00', $envelope['occurredAt']);
        self::assertSame($event->accountId->toRfc4122(), $envelope['aggregateId']);
    }

    public function testEventIsDiscardedWhenTheTransactionRollsBack(): void
    {
        $event = $this->event();

        try {
            $this->service(TransactionManager::class)->transactional(function () use ($event): void {
                $this->service(EventPublisher::class)->publish($event);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->fetchInt(
            'SELECT COUNT(*) FROM outbox_messages WHERE aggregate_id = :id',
            ['id' => $event->accountId->toRfc4122()],
        ));
    }

    public function testMarkPublishedAndMarkFailedTrackAttempts(): void
    {
        $message = OutboxMessage::fromDomainEvent($this->event());

        $message->markFailed(str_repeat('x', 3000));
        self::assertSame(1, $message->attempts());
        self::assertSame(2000, mb_strlen((string) $message->lastError()));

        $message->markPublished(new \DateTimeImmutable('2026-09-24 11:00:00'));
        self::assertSame(2, $message->attempts());
        self::assertNull($message->lastError());
        self::assertNotNull($message->publishedAt());
    }

    private function event(): AccountOpened
    {
        return new AccountOpened(Uuid::v7(), 'Alice', AccountType::CUSTOMER, 'USD', new \DateTimeImmutable('2026-09-24 10:00:00'));
    }
}
