<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

enum TransferKind: string
{
    case INTERNAL = 'internal';
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
}
