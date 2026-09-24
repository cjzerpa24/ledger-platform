<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Exception;

class LedgerException extends Exception
{
    public function __construct(string $message = 'Ledger exception', int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
