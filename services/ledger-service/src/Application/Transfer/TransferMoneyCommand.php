<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use Symfony\Component\Uid\Uuid;

final readonly class TransferMoneyCommand
{
    public function __construct(
        public Uuid $sourceAccountId,
        public Uuid $destinationAccountId,
        public string $amount,
        public ?string $description,
        public string $idempotencyKey,
    ) {}
}
