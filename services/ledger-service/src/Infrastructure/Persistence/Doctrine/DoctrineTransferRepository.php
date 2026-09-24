<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Transfer\Exception\TransferNotFound;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineTransferRepository implements TransferRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function add(Transfer $transfer): void
    {
        $this->entityManager->persist($transfer);
    }

    public function get(Uuid $id): Transfer
    {
        return $this->entityManager->find(Transfer::class, $id) ?? throw TransferNotFound::withId($id);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer
    {
        return $this->entityManager->getRepository(Transfer::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }
}
