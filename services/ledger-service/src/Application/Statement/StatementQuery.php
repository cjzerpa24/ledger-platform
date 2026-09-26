<?php

declare(strict_types=1);

namespace App\Application\Statement;

use Symfony\Component\Uid\Uuid;

final readonly class StatementQuery
{
    public function __construct(
        public Uuid $accountId,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {}
}
