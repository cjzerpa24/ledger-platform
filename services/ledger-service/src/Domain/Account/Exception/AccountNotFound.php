<?php

declare(strict_types=1);

namespace App\Domain\Account\Exception;

use App\Domain\Shared\LedgerException;
use Symfony\Component\Uid\Uuid;

final class AccountNotFound extends LedgerException
{
    public static function withId(Uuid $id): self
    {
        return new self(\sprintf('Account %s was not found.', $id->toRfc4122()));
    }
}
