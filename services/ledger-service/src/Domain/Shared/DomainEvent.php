<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Symfony\Component\Uid\Uuid;

interface DomainEvent
{
    public function eventName(): string;

    public function eventVersion(): int;

    public function aggregateId(): Uuid;

    public function occurredAt(): \DateTimeImmutable;

    /** @return array<string, mixed> */
    public function payload(): array;
}
