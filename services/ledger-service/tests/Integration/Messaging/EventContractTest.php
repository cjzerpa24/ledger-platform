<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\RecordWithdrawalCommand;
use App\Application\Transfer\RecordWithdrawalHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use App\Infrastructure\Messaging\IntegrationEvent;
use App\Infrastructure\Messaging\OutboxRelay;
use App\Tests\Support\IntegrationTestCase;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class EventContractTest extends IntegrationTestCase
{
    public function testEveryEmittedEventMatchesItsPublishedSchema(): void
    {
        $alice = Uuid::fromString($this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Alice', 'USD'))->id);
        $bob = Uuid::fromString($this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Bob', 'USD'))->id);
        $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand($alice, '100.00', 'Salary', 'c-dep'));
        $this->service(TransferMoneyHandler::class)->handle(new TransferMoneyCommand($alice, $bob, '10.00', null, 'c-tr'));
        $this->service(RecordWithdrawalHandler::class)->handle(new RecordWithdrawalCommand($bob, '5.00', 'ATM', 'c-wd'));

        $this->service(OutboxRelay::class)->relay(100);

        $transport = self::getContainer()->get('messenger.transport.ledger_events');
        \assert($transport instanceof InMemoryTransport);
        $seen = [];
        foreach ($transport->getSent() as $envelope) {
            $event = $envelope->getMessage();
            \assert($event instanceof IntegrationEvent);
            $this->assertMatchesSchema($event);
            $seen[$event->eventName] = true;
        }

        self::assertEqualsCanonicalizing(
            ['ledger.account_opened', 'ledger.deposit_recorded', 'ledger.transfer_completed', 'ledger.withdrawal_recorded'],
            array_keys($seen),
        );
    }

    private function assertMatchesSchema(IntegrationEvent $event): void
    {
        $dir = $_SERVER['CONTRACTS_DIR'] ?? $_ENV['CONTRACTS_DIR'] ?? \dirname(__DIR__, 5) . '/contracts';
        \assert(\is_string($dir));
        $schemaFile = \sprintf('%s/events/%s.v%d.schema.json', $dir, $event->eventName, $event->eventVersion);
        self::assertFileExists($schemaFile);

        $data = json_decode(json_encode($event->toArray(), \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        $result = new Validator()->validate($data, (string) file_get_contents($schemaFile));

        self::assertTrue(
            $result->isValid(),
            $event->eventName . ': ' . json_encode(null === $result->error() ? [] : new ErrorFormatter()->format($result->error())),
        );
    }
}
