<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';
    case BLOCKED = 'blocked';
    case CLOSED = 'closed';
}
