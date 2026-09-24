<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Exception;

use Exception;

class InsufficientFunds extends Exception
{
    public function __construct(string $message = 'Insufficient funds', int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
