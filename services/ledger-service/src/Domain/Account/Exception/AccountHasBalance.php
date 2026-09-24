<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Shared\LedgerException;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;
use Symfony\Component\Uid\Uuid;

final class AccountHasBalance extends LedgerException
{
    public static function forAccount(Uuid $id, Money $balance): self
    {
        return new self(\sprintf(
            'Account %s cannot be closed with a balance of %s %s.',
            $id->toRfc4122(),
            MoneyFormatter::format($balance),
            $balance->getCurrency()->getCode(),
        ));
    }
}
