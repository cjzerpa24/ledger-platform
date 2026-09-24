<?php

declare(strict_types=1);

namespace App\Domain\Shared;

trait RecordsEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /** @return list<DomainEvent> */
    public function pullEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    protected function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
