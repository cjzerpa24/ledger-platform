<?php

declare(strict_types=1);

namespace App\Application\Transfer;

class TransferViewDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly float $amount,
        public readonly string $description,
        public readonly string $status,
    ) {
    }
}
