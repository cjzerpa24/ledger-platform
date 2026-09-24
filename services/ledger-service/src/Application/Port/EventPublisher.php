<?php

declare(strict_types=1);

namespace App\Application\Port;

interface EventPublisher
{
    public function publish(DomainEvent $event): void;
}
