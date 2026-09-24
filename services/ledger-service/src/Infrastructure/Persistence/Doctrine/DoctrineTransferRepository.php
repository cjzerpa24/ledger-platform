<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Transfer\Transfer;

class DoctrineTransferRepository implements TransferRepository
{
    public function __construct(private EntityManager $entityManager)
    {
    }

    public function create(Transfer $transfer): void
    {
        $this->entityManager->persist($transfer);
        $this->entityManager->flush();
    }

    public function update(Transfer $transfer): void
    {
        $this->entityManager->persist($transfer);
        $this->entityManager->flush();
    }

    public function findById(Uuid $id): ?Transfer
    {
        return $this->entityManager->find(Transfer::class, $id);
    }

    public function findByCriteria(array $criteria): array
    {
        return $this->entityManager->getRepository(Transfer::class)->findBy($criteria);
    }

    public function approve(Uuid $id): void
    {
        $transfer = $this->findById($id);
        $transfer->setStatus(TransferStatus::APPROVED);
        $this->update($transfer);
    }

    public function reject(Uuid $id): void
    {
        $transfer = $this->findById($id);
        $transfer->setStatus(TransferStatus::REJECTED);
        $this->update($transfer);
    }
}
