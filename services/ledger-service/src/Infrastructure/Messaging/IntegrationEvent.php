<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Infrastructure\Persistence\Doctrine\Outbox\OutboxMessage;

/** Wire format published to other services; see /contracts/events. */
final readonly class IntegrationEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $eventName,
        public int $eventVersion,
        public string $occurredAt,
        public string $aggregateId,
        public array $payload,
    ) {}

    public static function fromOutbox(OutboxMessage $message): self
    {
        $envelope = $message->toEnvelope();

        return new self(
            $envelope['eventId'],
            $envelope['eventName'],
            $envelope['eventVersion'],
            $envelope['occurredAt'],
            $envelope['aggregateId'],
            $envelope['payload'],
        );
    }

    /** @return array{eventId: string, eventName: string, eventVersion: int, occurredAt: string, aggregateId: string, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'eventName' => $this->eventName,
            'eventVersion' => $this->eventVersion,
            'occurredAt' => $this->occurredAt,
            'aggregateId' => $this->aggregateId,
            'payload' => $this->payload,
        ];
    }
}
