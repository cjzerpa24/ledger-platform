<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Account\AccountStatus;
use App\Domain\Shared\LedgerException;
use Symfony\Component\Uid\Uuid;

final class AccountNotActive extends LedgerException
{
    public static function forAccount(Uuid $id, AccountStatus $status): self
    {
        return new self(\sprintf('Account %s is %s.', $id->toRfc4122(), $status->value));
    }
}
