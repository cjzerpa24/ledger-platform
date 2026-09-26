<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exception;

use App\Domain\Shared\LedgerException;

final class InvalidStatementPeriod extends LedgerException
{
    public static function fromAfterTo(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self(\sprintf('Statement "from" (%s) must not be after "to" (%s).', $from->format('Y-m-d'), $to->format('Y-m-d')));
    }

    public static function tooLong(int $maxDays): self
    {
        return new self(\sprintf('A statement may cover at most %d days.', $maxDays));
    }
}
