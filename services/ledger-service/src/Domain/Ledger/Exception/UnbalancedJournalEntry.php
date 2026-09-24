<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;

final class UnbalancedJournalEntry extends LedgerException
{
    public static function tooFewLines(int $count): self
    {
        return new self(\sprintf('A journal entry needs at least two postings, %d given.', $count));
    }

    public static function mixedCurrencies(): self
    {
        return new self('All postings in a journal entry must share one currency.');
    }

    public static function nonPositiveAmount(): self
    {
        return new self('Posting amounts must be greater than zero.');
    }

    public static function debitsNotEqualCredits(Money $debits, Money $credits): self
    {
        return new self(\sprintf(
            'Unbalanced journal entry: debits %s do not equal credits %s.',
            MoneyFormatter::format($debits),
            MoneyFormatter::format($credits),
        ));
    }
}
