<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Event;

final readonly class TransferCompleted extends TransferEvent
{
    public function eventName(): string
    {
        return 'ledger.transfer_completed';
    }
}
