<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final class InsufficientFunds extends LedgerException
{
    public static function forAccount(Uuid $id, Money $balance, Money $requested): self
    {
        return new self(\sprintf(
            'Account %s has insufficient funds: balance %s %s, requested %s %s.',
            $id->toRfc4122(),
            MoneyFormatter::format($balance),
            $balance->getCurrency()->getCode(),
            MoneyFormatter::format($requested),
            $requested->getCurrency()->getCode(),
        ));
    }
}
