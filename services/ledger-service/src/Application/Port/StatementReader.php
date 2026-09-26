<?php

declare(strict_types=1);

namespace App\Application\Port;

use Symfony\Component\Uid\Uuid;

/** Read model over postings; bypasses the entity layer. */
interface StatementReader
{
    public function openingBalanceMinor(Uuid $accountId, \DateTimeImmutable $before): int;

    /** @return list<StatementLine> ordered by (occurred_at, posting id) */
    public function lines(Uuid $accountId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array;
}
