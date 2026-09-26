<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Ledger\Direction;

final readonly class StatementLine
{
    public function __construct(
        public string $postingId,
        public \DateTimeImmutable $occurredAt,
        public ?string $description,
        public string $transferId,
        public Direction $direction,
        public int $amountMinor,
        public int $balanceAfterMinor,
    ) {}
}
