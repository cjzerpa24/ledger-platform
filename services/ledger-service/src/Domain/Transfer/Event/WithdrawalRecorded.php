<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Event;

final readonly class WithdrawalRecorded extends TransferEvent
{
    public function eventName(): string
    {
        return 'ledger.withdrawal_recorded';
    }
}
