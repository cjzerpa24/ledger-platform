<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\JournalEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineJournalEntryRepository implements JournalEntryRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function add(JournalEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }
}
