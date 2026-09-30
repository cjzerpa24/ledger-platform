<?php

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use App\Tests\Support\IntegrationTestCase;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Runs real parallel PHP processes against PostgreSQL. Fixtures are committed
 * (outside DAMA's per-test rollback) and removed again in tearDown().
 */
final class ConcurrentTransferTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $accountIds = [];

    protected function tearDown(): void
    {
        $ids = $this->accountIds;
        if ([] !== $ids) {
            $connection = $this->connection();
            $params = ['ids' => $ids];
            $types = ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING];
            $connection->executeStatement('DELETE FROM outbox_messages WHERE aggregate_id IN (SELECT id FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids)) OR aggregate_id IN (:ids)', $params, $types);
            $connection->executeStatement('DELETE FROM postings WHERE journal_entry_id IN (SELECT journal_entry_id FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids))', $params, $types);
            $connection->executeStatement('DELETE FROM journal_entries WHERE id IN (SELECT journal_entry_id FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids))', $params, $types);
            $connection->executeStatement('DELETE FROM transfers WHERE source_account_id IN (:ids) OR destination_account_id IN (:ids)', $params, $types);
            $connection->executeStatement('DELETE FROM accounts WHERE id IN (:ids)', $params, $types);
            $connection->executeStatement(
                "UPDATE accounts s SET balance_minor = COALESCE((SELECT SUM(CASE WHEN p.direction = 'credit' THEN p.amount_minor ELSE -p.amount_minor END) FROM postings p WHERE p.account_id = s.id), 0) WHERE s.type = 'settlement'",
            );
            StaticDriver::commit();
            StaticDriver::beginTransaction();
        }
        parent::tearDown();
    }

    public function testParallelTransfersNeverOverdraw(): void
    {
        $source = $this->fundedAccount('100.00');
        $destination = $this->fundedAccount(null);
        $this->commitFixtures();

        $results = $this->runWorkers(array_map(
            static fn(int $i): array => [$source, $destination, '40.00', "overdraw-{$i}"],
            range(1, 5),
        ));

        self::assertSame(2, \count(array_filter($results, static fn(array $r): bool => 'ok' === $r['result'])), json_encode($results, \JSON_THROW_ON_ERROR));
        self::assertSame(3, \count(array_filter($results, static fn(array $r): bool => 'InsufficientFunds' === $r['result'])));
        self::assertSame(2000, $this->balanceMinor($source));
        self::assertSame(8000, $this->balanceMinor($destination));
        self::assertTrue($this->service(BalanceVerifier::class)->verify()->isClean());
    }

    public function testOppositeDirectionTransfersDoNotDeadlock(): void
    {
        $a = $this->fundedAccount('100.00');
        $b = $this->fundedAccount('100.00');
        $this->commitFixtures();

        $results = $this->runWorkers(array_map(
            static fn(int $i): array => 0 === $i % 2 ? [$a, $b, '1.00', "swap-{$i}"] : [$b, $a, '1.00', "swap-{$i}"],
            range(1, 10),
        ));

        self::assertSame(array_fill(0, 10, 'ok'), array_column($results, 'result'), json_encode($results, \JSON_THROW_ON_ERROR));
        self::assertSame(10000, $this->balanceMinor($a));
        self::assertSame(10000, $this->balanceMinor($b));
    }

    public function testSameIdempotencyKeyFromManyWorkersCreatesOneTransfer(): void
    {
        $source = $this->fundedAccount('100.00');
        $destination = $this->fundedAccount(null);
        $this->commitFixtures();

        $results = $this->runWorkers(array_fill(0, 5, [$source, $destination, '10.00', 'same-key']));

        self::assertSame(array_fill(0, 5, 'ok'), array_column($results, 'result'), json_encode($results, \JSON_THROW_ON_ERROR));
        $transferIds = array_map(static fn(array $r): string => \is_string($r['transferId'] ?? null) ? $r['transferId'] : '', $results);
        self::assertCount(1, array_unique($transferIds));
        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM transfers WHERE idempotency_key = 'same-key'"));
        self::assertSame(9000, $this->balanceMinor($source));
    }

    private function fundedAccount(?string $deposit): string
    {
        $id = $this->service(OpenAccountHandler::class)->handle(new OpenAccountCommand('Concurrent', 'USD'))->id;
        $this->accountIds[] = $id;
        if (null !== $deposit) {
            $this->service(RecordDepositHandler::class)->handle(new RecordDepositCommand(Uuid::fromString($id), $deposit, null, 'fund-' . $id));
        }

        return $id;
    }

    private function commitFixtures(): void
    {
        StaticDriver::commit();
        StaticDriver::beginTransaction();
    }

    /**
     * @param list<array{string, string, string, string}> $jobs
     *
     * @return list<array<string, mixed>>
     */
    private function runWorkers(array $jobs): array
    {
        $startAt = (string) (microtime(true) + 3.0);
        $processes = [];
        foreach ($jobs as $job) {
            $process = new Process(['php', __DIR__ . '/transfer_worker.php', ...$job, $startAt], env: ['APP_ENV' => 'test']);
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $decoded = json_decode($process->getOutput(), true);
            self::assertIsArray($decoded, 'Worker output: ' . $process->getOutput() . $process->getErrorOutput());
            /* @var array<string, mixed> $decoded */
            $results[] = $decoded;
        }

        return $results;
    }

    private function balanceMinor(string $accountId): int
    {
        return $this->fetchInt('SELECT balance_minor FROM accounts WHERE id = :id', ['id' => $accountId]);
    }
}
