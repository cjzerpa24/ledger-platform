<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\DuplicateRecord;
use App\Application\Port\TransactionManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    public function transactional(callable $operation): mixed
    {
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $operation();
            $entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->discardUnitOfWork();

            if ($e instanceof UniqueConstraintViolationException) {
                throw new DuplicateRecord($e->getMessage(), previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Domain rejections leave the EntityManager open but dirty, so clear it.
     * A failed flush closes it, so replace it; the injected EntityManager
     * service is a lazy object that the registry resets in place.
     */
    private function discardUnitOfWork(): void
    {
        $entityManager = $this->entityManager();
        if ($entityManager->isOpen()) {
            $entityManager->clear();

            return;
        }

        $this->registry->resetManager();
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager();
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }
}
