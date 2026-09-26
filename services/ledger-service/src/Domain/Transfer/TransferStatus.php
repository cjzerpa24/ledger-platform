<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

/**
 * Stage 1 posts transfers synchronously, so only COMPLETED is persisted.
 * PENDING and similar states arrive with two-phase transfers in stage 2.
 */
enum TransferStatus: string
{
    case COMPLETED = 'completed';
}
