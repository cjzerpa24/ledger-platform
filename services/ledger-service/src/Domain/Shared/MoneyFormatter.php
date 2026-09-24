<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

final class MoneyFormatter
{
    public static function format(Money $money): string
    {
        return new DecimalMoneyFormatter(SupportedCurrencies::list())->format($money);
    }
}
