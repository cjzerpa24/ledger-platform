<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\UnsupportedCurrency;
use App\Domain\Shared\SupportedCurrencies;
use PHPUnit\Framework\TestCase;

final class SupportedCurrenciesTest extends TestCase
{
    public function testReturnsSupportedCurrencyNormalisingCase(): void
    {
        self::assertSame('USD', SupportedCurrencies::get(' usd ')->getCode());
    }

    public function testExposesMinorUnits(): void
    {
        self::assertSame(2, SupportedCurrencies::minorUnits(SupportedCurrencies::get('TZS')));
        self::assertSame(0, SupportedCurrencies::minorUnits(SupportedCurrencies::get('JPY')));
    }

    public function testRejectsUnsupportedCurrency(): void
    {
        $this->expectException(UnsupportedCurrency::class);
        $this->expectExceptionMessage('Currency "CHF" is not supported. Supported: USD, EUR, GBP, TZS, JPY.');

        SupportedCurrencies::get('CHF');
    }
}
