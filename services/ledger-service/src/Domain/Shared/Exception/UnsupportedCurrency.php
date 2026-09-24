<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\SupportedCurrencies;

final class UnsupportedCurrency extends LedgerException
{
    public static function code(string $code): self
    {
        return new self(\sprintf(
            'Currency "%s" is not supported. Supported: %s.',
            $code,
            implode(', ', SupportedCurrencies::codes()),
        ));
    }
}
