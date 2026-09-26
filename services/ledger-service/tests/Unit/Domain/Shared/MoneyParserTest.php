<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvalidAmount;
use App\Domain\Shared\MoneyParser;
use App\Domain\Shared\SupportedCurrencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function validAmounts(): iterable
    {
        yield 'whole dollars' => ['50', 'USD', '5000'];
        yield 'two decimals' => ['10.05', 'USD', '1005'];
        yield 'one decimal' => ['0.1', 'EUR', '10'];
        yield 'yen has no minor units' => ['1200', 'JPY', '1200'];
        yield 'surrounding whitespace is trimmed' => [' 7.50 ', 'GBP', '750'];
    }

    #[DataProvider('validAmounts')]
    public function testParsesExactMinorUnits(string $decimal, string $currency, string $expectedMinor): void
    {
        $money = MoneyParser::parsePositive($decimal, SupportedCurrencies::get($currency));

        self::assertSame($expectedMinor, $money->getAmount());
        self::assertSame($currency, $money->getCurrency()->getCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidAmounts(): iterable
    {
        yield 'too many decimals' => ['10.001', 'USD'];
        yield 'decimals on yen' => ['10.5', 'JPY'];
        yield 'zero' => ['0.00', 'USD'];
        yield 'negative' => ['-5', 'USD'];
        yield 'exponent' => ['1e3', 'USD'];
        yield 'trailing dot' => ['10.', 'USD'];
        yield 'empty' => ['', 'USD'];
        yield 'letters' => ['ten', 'USD'];
        yield 'exceeds 64-bit minor units' => ['99999999999999999999999', 'USD'];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsInvalidAmounts(string $decimal, string $currency): void
    {
        $this->expectException(InvalidAmount::class);

        MoneyParser::parsePositive($decimal, SupportedCurrencies::get($currency));
    }
}
