<?php

declare(strict_types=1);

namespace App\Application\Statement;

final readonly class StatementLineView
{
    public function __construct(
        public string $postingId,
        public string $occurredAt,
        public ?string $description,
        public string $transferId,
        public string $direction,
        public string $amount,
        public string $balanceAfter,
    ) {}
}
