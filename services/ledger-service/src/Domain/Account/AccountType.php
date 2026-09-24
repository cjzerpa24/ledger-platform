<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountType: string
{
    case CUSTOMER = 'customer';
    case SETTLEMENT = 'settlement';
}
