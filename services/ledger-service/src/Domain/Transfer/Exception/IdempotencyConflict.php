<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use App\Domain\Shared\LedgerException;

final class IdempotencyConflict extends LedgerException
{
    public static function forKey(string $key): self
    {
        return new self(\sprintf('Idempotency-Key "%s" was already used with a different request.', $key));
    }
}
