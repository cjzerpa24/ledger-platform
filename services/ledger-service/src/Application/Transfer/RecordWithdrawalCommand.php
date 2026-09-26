<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use Symfony\Component\Uid\Uuid;

final readonly class RecordWithdrawalCommand
{
    public function __construct(
        public Uuid $accountId,
        public string $amount,
        public ?string $description,
        public string $idempotencyKey,
    ) {}
}
