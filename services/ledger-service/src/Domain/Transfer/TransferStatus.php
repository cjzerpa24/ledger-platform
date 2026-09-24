<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

enum TransferStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
