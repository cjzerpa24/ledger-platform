<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Outbox;

use App\Application\Port\EventPublisher;
use App\Domain\Shared\DomainEvent;
use Doctrine\ORM\EntityManagerInterface;

final class OutboxEventPublisher implements EventPublisher
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function publish(DomainEvent $event): void
    {
        $this->entityManager->persist(OutboxMessage::fromDomainEvent($event));
    }
}
