<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class TransferApiTest extends ApiTestCase
{
    private const string USD_SETTLEMENT = '0199a000-0000-7000-8000-000000000840';

    public function testDepositCreditsAccountFromSettlement(): void
    {
        $alice = $this->openAccount();

        $response = $this->deposit($alice, '100.00', 'dep-1');

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('deposit', $body['kind']);
        self::assertSame(self::USD_SETTLEMENT, $body['sourceAccountId']);
        self::assertSame($alice, $body['destinationAccountId']);
        self::assertSame('100.00', $body['amount']);
        self::assertSame('USD', $body['currency']);
        self::assertSame('completed', $body['status']);
        self::assertSame('100.00', $this->balance($alice));
    }

    public function testReplayingAKeyReturnsTheOriginalTransferOnce(): void
    {
        $alice = $this->openAccount();
        $first = $this->deposit($alice, '100.00', 'dep-replay');

        $second = $this->deposit($alice, '100.00', 'dep-replay');

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
        self::assertSame($this->json($first), $this->json($second));
        self::assertSame('100.00', $this->balance($alice));
    }

    public function testReusingAKeyWithADifferentRequestConflicts(): void
    {
        $alice = $this->openAccount();
        $this->deposit($alice, '100.00', 'dep-conflict');

        $this->assertProblem($this->deposit($alice, '99.00', 'dep-conflict'), 409, 'urn:ledger:problem:idempotency-conflict');
    }

    public function testMissingIdempotencyKeyIsBadRequest(): void
    {
        $alice = $this->openAccount();

        $problem = $this->assertProblem(
            $this->request('POST', "/accounts/{$alice}/deposits", ['amount' => '1.00']),
            400,
            'about:blank',
        );
        self::assertIsString($problem['detail']);
        self::assertStringContainsString('Idempotency-Key', $problem['detail']);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedAmounts(): iterable
    {
        yield 'json number' => [10.5, 'urn:ledger:problem:validation-failed'];
        yield 'negative' => ['-5', 'urn:ledger:problem:validation-failed'];
        yield 'exponent' => ['1e3', 'urn:ledger:problem:validation-failed'];
        yield 'trailing dot' => ['10.', 'urn:ledger:problem:validation-failed'];
        yield 'too precise' => ['10.001', 'urn:ledger:problem:invalid-amount'];
        yield 'zero' => ['0', 'urn:ledger:problem:invalid-amount'];
        yield 'overflow' => ['9999999999999999999999999', 'urn:ledger:problem:invalid-amount'];
    }

    #[DataProvider('malformedAmounts')]
    public function testRejectsMalformedAmounts(mixed $amount, string $type): void
    {
        $alice = $this->openAccount();

        $response = $this->request('POST', "/accounts/{$alice}/deposits", ['amount' => $amount], ['Idempotency-Key' => 'bad-amount']);

        $this->assertProblem($response, 422, $type);
        self::assertSame('0.00', $this->balance($alice));
    }

    public function testDepositToUnknownAccountIsNotFound(): void
    {
        $this->assertProblem($this->deposit('0199a000-0000-7000-8000-00000000dead', '1.00'), 404, 'urn:ledger:problem:account-not-found');
    }

    public function testTransferMovesMoneyAndIsRetrievable(): void
    {
        [$alice, $bob] = [$this->openAccount(), $this->openAccount('USD', 'Bob')];
        $this->deposit($alice, '100.00');

        $response = $this->transfer($alice, $bob, '40.00', 'tr-1');

        self::assertSame(201, $response->getStatusCode());
        $transfer = $this->json($response);
        self::assertIsString($transfer['id']);
        self::assertSame('/transfers/' . $transfer['id'], $response->headers->get('Location'));
        self::assertSame('60.00', $this->balance($alice));
        self::assertSame('40.00', $this->balance($bob));
        self::assertSame($transfer, $this->json($this->request('GET', '/transfers/' . $transfer['id'])));
    }

    public function testInsufficientFundsChangesNothing(): void
    {
        [$alice, $bob] = [$this->openAccount(), $this->openAccount('USD', 'Bob')];
        $this->deposit($alice, '10.00');

        $this->assertProblem($this->transfer($alice, $bob, '10.01', 'tr-nsf'), 422, 'urn:ledger:problem:insufficient-funds');

        self::assertSame('10.00', $this->balance($alice));
        self::assertSame('0.00', $this->balance($bob));
    }

    public function testCurrencyMismatchIsRejected(): void
    {
        [$usd, $eur] = [$this->openAccount(), $this->openAccount('EUR', 'Euro')];
        $this->deposit($usd, '10.00');

        $this->assertProblem($this->transfer($usd, $eur, '1.00', 'tr-fx'), 422, 'urn:ledger:problem:currency-mismatch');
    }

    public function testSameAccountTransferIsRejected(): void
    {
        $alice = $this->openAccount();

        $this->assertProblem($this->transfer($alice, $alice, '1.00', 'tr-self'), 422, 'urn:ledger:problem:same-account-transfer');
    }

    public function testWithdrawalDebitsAccountAndRespectsBalance(): void
    {
        $alice = $this->openAccount();
        $this->deposit($alice, '50.00');

        $ok = $this->request('POST', "/accounts/{$alice}/withdrawals", ['amount' => '20.00'], ['Idempotency-Key' => 'wd-1']);
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame('withdrawal', $this->json($ok)['kind']);
        self::assertSame(self::USD_SETTLEMENT, $this->json($ok)['destinationAccountId']);
        self::assertSame('30.00', $this->balance($alice));

        $this->assertProblem(
            $this->request('POST', "/accounts/{$alice}/withdrawals", ['amount' => '30.01'], ['Idempotency-Key' => 'wd-2']),
            422,
            'urn:ledger:problem:insufficient-funds',
        );
    }

    public function testUnknownTransferIsNotFound(): void
    {
        $this->assertProblem($this->request('GET', '/transfers/0199a000-0000-7000-8000-00000000dead'), 404, 'urn:ledger:problem:transfer-not-found');
    }

    private function transfer(string $from, string $to, string $amount, string $key): Response
    {
        return $this->request('POST', '/transfers', [
            'sourceAccountId' => $from,
            'destinationAccountId' => $to,
            'amount' => $amount,
        ], ['Idempotency-Key' => $key]);
    }

    private function balance(string $accountId): string
    {
        $balance = $this->json($this->request('GET', "/accounts/{$accountId}"))['balance'];
        self::assertIsString($balance);

        return $balance;
    }
}
