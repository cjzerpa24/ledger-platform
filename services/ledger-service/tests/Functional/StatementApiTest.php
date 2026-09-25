<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class StatementApiTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    public function testStatementHasOpeningLinesWithRunningBalanceAndClosing(): void
    {
        $alice = $this->openAccount();
        $bob = $this->openAccount('USD', 'Bob');

        self::mockTime('2026-03-01 09:00:00');
        $this->deposit($alice, '100.00', 'st-dep');
        self::mockTime('2026-03-05 12:00:00');
        $this->transfer($alice, $bob, '30.00', 'st-t1', 'Groceries');
        self::mockTime('2026-03-06 23:59:59');
        $this->transfer($alice, $bob, '5.00', 'st-t2', 'Coffee');
        self::mockTime('2026-03-07 00:00:00');
        $this->transfer($alice, $bob, '1.00', 'st-t3', 'Next day');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement?from=2026-03-05&to=2026-03-06"));

        self::assertSame($alice, $statement['accountId']);
        self::assertSame('USD', $statement['currency']);
        self::assertSame('2026-03-05', $statement['from']);
        self::assertSame('2026-03-06', $statement['to']);
        self::assertSame('100.00', $statement['openingBalance']);
        self::assertSame('65.00', $statement['closingBalance']);
        $lines = $this->lines($statement);
        self::assertCount(2, $lines);
        self::assertSame(['debit', '30.00', '70.00', 'Groceries'], [$lines[0]['direction'], $lines[0]['amount'], $lines[0]['balanceAfter'], $lines[0]['description']]);
        self::assertSame(['debit', '5.00', '65.00', 'Coffee'], [$lines[1]['direction'], $lines[1]['amount'], $lines[1]['balanceAfter'], $lines[1]['description']]);
        self::assertSame('2026-03-05T12:00:00.000+00:00', $lines[0]['occurredAt']);
    }

    public function testFromDateIsInclusiveAtMidnight(): void
    {
        $alice = $this->openAccount();
        self::mockTime('2026-04-10 00:00:00');
        $this->deposit($alice, '10.00', 'st-midnight');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement?from=2026-04-10&to=2026-04-10"));

        self::assertSame('0.00', $statement['openingBalance']);
        self::assertCount(1, $this->lines($statement));
        self::assertSame('10.00', $statement['closingBalance']);
    }

    public function testEmptyPeriodClosesAtOpeningBalance(): void
    {
        $alice = $this->openAccount();
        self::mockTime('2026-05-01 10:00:00');
        $this->deposit($alice, '10.00', 'st-empty');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement?from=2026-06-01&to=2026-06-30"));

        self::assertSame('10.00', $statement['openingBalance']);
        self::assertSame([], $statement['lines']);
        self::assertSame('10.00', $statement['closingBalance']);
    }

    public function testDefaultsToTheLastThirtyDays(): void
    {
        $alice = $this->openAccount();
        self::mockTime('2026-07-31 15:00:00');

        $statement = $this->json($this->request('GET', "/accounts/{$alice}/statement"));

        self::assertSame('2026-07-01', $statement['from']);
        self::assertSame('2026-07-31', $statement['to']);
    }

    public function testRejectsInvalidPeriods(): void
    {
        $alice = $this->openAccount();

        $this->assertProblem($this->request('GET', "/accounts/{$alice}/statement?from=2026-03-10&to=2026-03-01"), 422, 'urn:ledger:problem:invalid-statement-period');
        $this->assertProblem($this->request('GET', "/accounts/{$alice}/statement?from=2025-01-01&to=2026-03-01"), 422, 'urn:ledger:problem:invalid-statement-period');
        $this->assertProblem($this->request('GET', "/accounts/{$alice}/statement?from=03/01/2026"), 422, 'urn:ledger:problem:validation-failed');
    }

    public function testUnknownAccountIsNotFound(): void
    {
        $this->assertProblem($this->request('GET', '/accounts/0199a000-0000-7000-8000-00000000dead/statement'), 404, 'urn:ledger:problem:account-not-found');
    }

    /**
     * @param array<string, mixed> $statement
     *
     * @return list<array<string, mixed>>
     */
    private function lines(array $statement): array
    {
        $lines = $statement['lines'];
        self::assertIsList($lines);
        $typed = [];
        foreach ($lines as $line) {
            self::assertIsArray($line);
            $typed[] = array_combine(array_map(strval(...), array_keys($line)), $line);
        }

        return $typed;
    }

    private function transfer(string $from, string $to, string $amount, string $key, string $description): void
    {
        $response = $this->request('POST', '/transfers', [
            'sourceAccountId' => $from,
            'destinationAccountId' => $to,
            'amount' => $amount,
            'description' => $description,
        ], ['Idempotency-Key' => $key]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }
}
