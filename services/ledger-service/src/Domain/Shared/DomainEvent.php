<?php

declare(strict_types=1);

namespace App\Domain\Shared;

interface DomainEvent
{
    public function getEventName(): string;
    public function getEventData(): array;
}
