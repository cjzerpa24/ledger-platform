<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Shared\DomainEvent;

interface EventPublisher
{
    /**
     * Records the event for delivery. It is only stored when the surrounding
     * TransactionManager::transactional() call commits.
     */
    public function publish(DomainEvent $event): void;
}
