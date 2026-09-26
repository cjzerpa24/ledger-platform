<?php

declare(strict_types=1);

namespace App\Application\Statement;

final readonly class StatementView
{
    /** @param list<StatementLineView> $lines */
    public function __construct(
        public string $accountId,
        public string $currency,
        public string $from,
        public string $to,
        public string $openingBalance,
        public string $closingBalance,
        public array $lines,
    ) {}
}
