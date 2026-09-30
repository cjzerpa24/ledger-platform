<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

final readonly class BalanceReport
{
    /**
     * @param list<array{accountId: string, cachedMinor: int, computedMinor: int}> $accountMismatches
     * @param list<array{currency: string, totalMinor: int}>                       $currencyImbalances
     */
    public function __construct(public array $accountMismatches, public array $currencyImbalances) {}

    public function isClean(): bool
    {
        return [] === $this->accountMismatches && [] === $this->currencyImbalances;
    }
}
