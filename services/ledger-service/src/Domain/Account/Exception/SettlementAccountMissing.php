<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use Money\Currency;

/** A configuration fault (missing seed data), not a business rule: surfaces as 500. */
final class SettlementAccountMissing extends \LogicException
{
    public static function forCurrency(Currency $currency): self
    {
        return new self(\sprintf('No settlement account exists for %s; run the migrations.', $currency->getCode()));
    }
}
