<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Money\Money;
use Symfony\Component\Uid\Uuid;

/** One side of a journal entry before it is recorded. */
final readonly class PostingLine
{
    public function __construct(
        public Uuid $accountId,
        public Direction $direction,
        public Money $amount,
        public Money $balanceAfter,
    ) {}
}
