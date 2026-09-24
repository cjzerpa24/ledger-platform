<?php

declare(strict_types=1);

namespace App\Application\Transfer;

class TransferMoneyDTO
{
    public function __construct(
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly float $amount,
        public readonly string $description,
    ) {
    }
}
