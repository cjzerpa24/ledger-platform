<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use App\Domain\Shared\LedgerException;

final class InvalidAmount extends LedgerException
{
    public static function notDecimal(string $amount): self
    {
        return new self(\sprintf('Amount "%s" must be a positive decimal string such as "10.50".', $amount));
    }

    public static function tooPrecise(string $amount, string $currency, int $minorUnits): self
    {
        return new self(\sprintf('Amount "%s" has more than %d decimal places allowed for %s.', $amount, $minorUnits, $currency));
    }

    public static function notPositive(string $amount): self
    {
        return new self(\sprintf('Amount "%s" must be greater than zero.', $amount));
    }

    public static function tooLarge(string $amount): self
    {
        return new self(\sprintf('Amount "%s" exceeds the maximum supported value.', $amount));
    }
}
