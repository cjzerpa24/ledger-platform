<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private EntityManager $entityManager)
    {
    }

    public function begin(): void
    {
        $this->entityManager->beginTransaction();
    }
    
    public function commit(): void
    {
        $this->entityManager->commit();
    }

    public function rollback(): void
    {
        $this->entityManager->rollback();
    }
}
