<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\Clock\SecondPrecisionClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SecondPrecisionClockTest extends TestCase
{
    public function testDropsSubSecondPrecisionSoStoredAndReturnedTimesMatch(): void
    {
        $clock = new SecondPrecisionClock(new MockClock('2026-09-24 10:15:30.987654', 'UTC'));

        self::assertSame('2026-09-24 10:15:30.000000', $clock->now()->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    }
}
