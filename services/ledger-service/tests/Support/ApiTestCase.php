<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function request(string $method, string $uri, ?array $body = null, array $headers = []): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    protected function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    protected function openAccount(string $currency = 'USD', string $name = 'Alice'): string
    {
        $response = $this->request('POST', '/accounts', ['name' => $name, 'currency' => $currency]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    protected function deposit(string $accountId, string $amount, ?string $key = null): Response
    {
        return $this->request('POST', "/accounts/{$accountId}/deposits", ['amount' => $amount], ['Idempotency-Key' => $key ?? bin2hex(random_bytes(8))]);
    }

    /** @return array<string, mixed> */
    protected function assertProblem(Response $response, int $status, string $type): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $problem = $this->json($response);
        self::assertSame($type, $problem['type']);
        self::assertSame($status, $problem['status']);

        return $problem;
    }

    /** @param array<string, mixed> $params */
    protected function fetchInt(string $sql, array $params = []): int
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $value = $connection->fetchOne($sql, $params);
        self::assertIsNumeric($value, \sprintf('Expected a number from: %s', $sql));

        return (int) $value;
    }
}
