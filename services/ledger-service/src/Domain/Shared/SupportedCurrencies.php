<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Shared\Exception\UnsupportedCurrency;
use Money\Currencies\CurrencyList;
use Money\Currency;

/**
 * The currencies this ledger accepts, with their ISO-4217 minor-unit exponent.
 * Every entry needs a settlement account (see the settlement seed migration).
 */
final class SupportedCurrencies
{
    private const array MINOR_UNITS = [
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'TZS' => 2,
        'JPY' => 0,
    ];

    public static function get(string $code): Currency
    {
        $code = strtoupper(trim($code));
        if ('' === $code || !\array_key_exists($code, self::MINOR_UNITS)) {
            throw UnsupportedCurrency::code($code);
        }

        return new Currency($code);
    }

    public static function minorUnits(Currency $currency): int
    {
        return self::list()->subunitFor($currency);
    }

    public static function list(): CurrencyList
    {
        return new CurrencyList(self::MINOR_UNITS);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::MINOR_UNITS);
    }
}
