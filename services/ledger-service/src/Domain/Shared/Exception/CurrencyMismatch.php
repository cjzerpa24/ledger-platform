<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use App\Domain\Shared\LedgerException;
use Money\Currency;

final class CurrencyMismatch extends LedgerException
{
    public static function between(Currency $expected, Currency $actual): self
    {
        return new self(\sprintf('Currency mismatch: expected %s, got %s.', $expected->getCode(), $actual->getCode()));
    }
}
