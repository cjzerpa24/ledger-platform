<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    protected function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    protected function connection(): Connection
    {
        return $this->service(Connection::class);
    }

    /** @param array<string, mixed> $params */
    protected function fetchInt(string $sql, array $params = []): int
    {
        $value = $this->connection()->fetchOne($sql, $params);
        self::assertIsNumeric($value, \sprintf('Expected a number from: %s', $sql));

        return (int) $value;
    }
}
