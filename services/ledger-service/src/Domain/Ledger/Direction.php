<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum Direction: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';
}
