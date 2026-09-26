<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Application\Port\Clock;
use Symfony\Component\Clock\ClockInterface;

/** PostgreSQL columns are TIMESTAMP(0) under DBAL 4, so drop sub-seconds up front. */
final class SecondPrecisionClock implements Clock
{
    public function __construct(private readonly ClockInterface $clock) {}

    public function now(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }
}
