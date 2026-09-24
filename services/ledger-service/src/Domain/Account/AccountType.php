<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountType: string
{
    case CHECKING = 'checking';
    case SAVINGS = 'savings';
    case CREDIT_CARD = 'credit_card';
    case LOAN = 'loan';
    case OTHER = 'other';
}
