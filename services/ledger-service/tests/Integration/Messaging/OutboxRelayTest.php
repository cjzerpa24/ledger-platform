<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Port\TransactionManager;
use App\Infrastructure\Messaging\IntegrationEvent;
use App\Infrastructure\Messaging\OutboxRelay;
use App\Tests\Support\IntegrationTestCase;
use App\Application\Port\Clock;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class OutboxRelayTest extends IntegrationTestCase
{
    public function testPublishesPendingEventsOnceAndMarksThemPublished(): void
    {
        $account = $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'));

        $first = $this->service(OutboxRelay::class)->relay(100);
        $second = $this->service(OutboxRelay::class)->relay(100);

        self::assertSame(1, $first->published);
        self::assertSame(0, $second->published);
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(IntegrationEvent::class, $message);
        self::assertSame('ledger.account_opened', $message->eventName);
        self::assertSame($account->id, $message->aggregateId);
        self::assertNotNull($this->connection()->fetchOne('SELECT published_at FROM outbox_messages WHERE aggregate_id = :id', ['id' => $account->id]));
    }

    public function testFailedPublishKeepsMessagePendingAndRecordsError(): void
    {
        $account = $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'));
        $failingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('redis down');
            }
        };
        $relay = new OutboxRelay($this->em(), $this->service(TransactionManager::class), $failingBus, $this->service(Clock::class), new NullLogger());

        $result = $relay->relay(100);

        self::assertSame(0, $result->published);
        self::assertSame(1, $result->failed);
        $row = $this->connection()->fetchAssociative('SELECT published_at, attempts, last_error FROM outbox_messages WHERE aggregate_id = :id', ['id' => $account->id]);
        self::assertSame(['published_at' => null, 'attempts' => 1, 'last_error' => 'redis down'], $row);
    }

    public function testCommandRelaysABatch(): void
    {
        $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'));
        $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Bob', 'USD'));
        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $tester = new CommandTester(new Application($kernel)->find('ledger:outbox:relay'));

        $tester->execute(['--batch' => '1']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Published 1, failed 0', $tester->getDisplay());
        self::assertCount(1, $this->transport()->getSent());
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.ledger_events');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
