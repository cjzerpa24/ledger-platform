<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

final class AccountApiTest extends ApiTestCase
{
    public function testOpensAccountAndReturnsView(): void
    {
        $response = $this->request('POST', '/accounts', ['name' => 'Alice', 'currency' => 'USD']);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertIsString($body['id']);
        self::assertSame('/accounts/' . $body['id'], $response->headers->get('Location'));
        self::assertSame('Alice', $body['name']);
        self::assertSame('customer', $body['type']);
        self::assertSame('USD', $body['currency']);
        self::assertSame('active', $body['status']);
        self::assertSame('0.00', $body['balance']);
    }

    public function testYenBalanceHasNoDecimals(): void
    {
        $id = $this->openAccount('JPY');

        self::assertSame('0', $this->json($this->request('GET', "/accounts/{$id}"))['balance']);
    }

    public function testShowReturnsTheOpenedAccount(): void
    {
        $id = $this->openAccount('EUR', 'Bob');

        $body = $this->json($this->request('GET', "/accounts/{$id}"));

        self::assertSame($id, $body['id']);
        self::assertSame('Bob', $body['name']);
    }

    public function testOpeningWritesAccountOpenedToTheOutbox(): void
    {
        $id = $this->openAccount();

        self::assertSame(1, $this->fetchInt(
            "SELECT COUNT(*) FROM outbox_messages WHERE aggregate_id = :id AND event_name = 'ledger.account_opened'",
            ['id' => $id],
        ));
    }

    public function testUnknownAccountIsNotFoundProblem(): void
    {
        $problem = $this->assertProblem(
            $this->request('GET', '/accounts/0199a000-0000-7000-8000-00000000dead'),
            404,
            'urn:ledger:problem:account-not-found',
        );
        self::assertSame('/accounts/0199a000-0000-7000-8000-00000000dead', $problem['instance']);
    }

    public function testNonUuidPathIsNotFound(): void
    {
        $this->assertProblem($this->request('GET', '/accounts/not-a-uuid'), 404, 'about:blank');
    }

    public function testValidationErrorsListViolations(): void
    {
        $problem = $this->assertProblem(
            $this->request('POST', '/accounts', ['currency' => 'usd']),
            422,
            'urn:ledger:problem:validation-failed',
        );

        self::assertIsArray($problem['violations']);
        $fields = array_column($problem['violations'], 'field');
        self::assertContains('name', $fields);
        self::assertContains('currency', $fields);
    }

    public function testUnsupportedCurrencyIsRejected(): void
    {
        $this->assertProblem(
            $this->request('POST', '/accounts', ['name' => 'Alice', 'currency' => 'CHF']),
            422,
            'urn:ledger:problem:unsupported-currency',
        );
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $this->client->request('POST', '/accounts', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":');

        $this->assertProblem($this->client->getResponse(), 400, 'about:blank');
    }
}
