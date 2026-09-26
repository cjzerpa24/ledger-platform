<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\Exception\InvalidAmount;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;

/**
 * Turns an API decimal string into exact minor units. Stricter than MoneyPHP's
 * parser: no signs, exponents or excess precision, and the result must fit in
 * a 64-bit integer so it can be stored as BIGINT.
 */
final class MoneyParser
{
    private const string DECIMAL_PATTERN = '/^\d+(?:\.(\d+))?$/';

    public static function parsePositive(string $decimal, Currency $currency): Money
    {
        $decimal = trim($decimal);
        if (1 !== preg_match(self::DECIMAL_PATTERN, $decimal, $matches)) {
            throw InvalidAmount::notDecimal($decimal);
        }

        $minorUnits = SupportedCurrencies::minorUnits($currency);
        if (\strlen($matches[1] ?? '') > $minorUnits) {
            throw InvalidAmount::tooPrecise($decimal, $currency->getCode(), $minorUnits);
        }

        $money = new DecimalMoneyParser(SupportedCurrencies::list())->parse($decimal, $currency);

        if (!$money->isPositive()) {
            throw InvalidAmount::notPositive($decimal);
        }
        if (bccomp($money->getAmount(), (string) \PHP_INT_MAX) > 0) {
            throw InvalidAmount::tooLarge($decimal);
        }

        return $money;
    }
}
