<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\MoneyFormatter;
use App\Domain\Shared\SupportedCurrencies;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    public function testFormatsUsingCurrencyPrecision(): void
    {
        self::assertSame('50.00', MoneyFormatter::format(new Money(5000, SupportedCurrencies::get('USD'))));
        self::assertSame('0.05', MoneyFormatter::format(new Money(5, SupportedCurrencies::get('EUR'))));
        self::assertSame('1200', MoneyFormatter::format(new Money(1200, SupportedCurrencies::get('JPY'))));
        self::assertSame('-12.34', MoneyFormatter::format(new Money(-1234, SupportedCurrencies::get('TZS'))));
    }
}
