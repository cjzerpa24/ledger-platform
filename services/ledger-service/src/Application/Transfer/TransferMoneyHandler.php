<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Transfer\TransferKind;

final class TransferMoneyHandler
{
    public function __construct(private readonly PostTransferService $poster) {}

    public function handle(TransferMoneyCommand $command): PostTransferResult
    {
        return $this->poster->post(new PostTransferCommand(
            TransferKind::INTERNAL,
            $command->sourceAccountId,
            $command->destinationAccountId,
            $command->amount,
            $command->description,
            $command->idempotencyKey,
        ));
    }
}
