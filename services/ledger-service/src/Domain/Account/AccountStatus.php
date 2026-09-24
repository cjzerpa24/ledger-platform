<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountStatus: string
{
    case ACTIVE = 'active';
    case FROZEN = 'frozen';
    case CLOSED = 'closed';
}
