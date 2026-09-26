<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Outbox;

use App\Domain\Shared\DomainEvent;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A domain event waiting to be relayed. Written in the same transaction as
 * the change that raised it, so events are never lost or sent for a rollback.
 */
#[ORM\Entity]
#[ORM\Table(name: 'outbox_messages')]
#[ORM\Index(name: 'idx_outbox_pending', columns: ['published_at', 'occurred_at'])]
class OutboxMessage
{
    private const int MAX_ERROR_LENGTH = 2000;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /** @param array<string, mixed> $payload */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(length: 100)]
        private string $eventName,
        #[ORM\Column(type: Types::INTEGER)]
        private int $eventVersion,
        #[ORM\Column(type: 'uuid')]
        private Uuid $aggregateId,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $payload,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
    ) {}

    public static function fromDomainEvent(DomainEvent $event): self
    {
        return new self(Uuid::v7(), $event->eventName(), $event->eventVersion(), $event->aggregateId(), $event->payload(), $event->occurredAt());
    }

    public function markPublished(\DateTimeImmutable $at): void
    {
        $this->publishedAt = $at;
        ++$this->attempts;
        $this->lastError = null;
    }

    public function markFailed(string $error): void
    {
        ++$this->attempts;
        $this->lastError = mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * @return array{eventId: string, eventName: string, eventVersion: int, occurredAt: string, aggregateId: string, payload: array<string, mixed>}
     */
    public function toEnvelope(): array
    {
        return [
            'eventId' => $this->id->toRfc4122(),
            'eventName' => $this->eventName,
            'eventVersion' => $this->eventVersion,
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'aggregateId' => $this->aggregateId->toRfc4122(),
            'payload' => $this->payload,
        ];
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
