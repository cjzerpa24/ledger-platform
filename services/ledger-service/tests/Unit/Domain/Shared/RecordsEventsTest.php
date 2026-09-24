<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\DomainEvent;
use App\Domain\Shared\RecordsEvents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RecordsEventsTest extends TestCase
{
    public function testPullReturnsRecordedEventsOnce(): void
    {
        $event = new class implements DomainEvent {
            public function eventName(): string
            {
                return 'test.happened';
            }

            public function eventVersion(): int
            {
                return 1;
            }

            public function aggregateId(): Uuid
            {
                return Uuid::v7();
            }

            public function occurredAt(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }

            public function payload(): array
            {
                return [];
            }
        };
        $aggregate = new class {
            use RecordsEvents;

            public function happen(DomainEvent $event): void
            {
                $this->record($event);
            }
        };

        $aggregate->happen($event);

        self::assertSame([$event], $aggregate->pullEvents());
        self::assertSame([], $aggregate->pullEvents());
    }
}
