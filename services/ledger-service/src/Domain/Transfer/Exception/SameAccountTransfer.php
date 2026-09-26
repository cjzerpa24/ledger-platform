<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\LedgerException;
use Symfony\Component\Uid\Uuid;

final class SameAccountTransfer extends LedgerException
{
    public static function forAccount(Uuid $accountId): self
    {
        return new self(\sprintf('Source and destination must differ; both are %s.', $accountId->toRfc4122()));
    }
}
