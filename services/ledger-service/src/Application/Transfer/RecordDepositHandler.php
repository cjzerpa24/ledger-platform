<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Account\AccountRepository;
use App\Domain\Transfer\TransferKind;

/** Money arriving from outside the ledger: settlement → customer. */
final class RecordDepositHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PostTransferService $poster,
    ) {}

    public function handle(RecordDepositCommand $command): PostTransferResult
    {
        $account = $this->accounts->get($command->accountId);
        $settlement = $this->accounts->getSettlement($account->currency());

        return $this->poster->post(new PostTransferCommand(
            TransferKind::DEPOSIT,
            $settlement->id(),
            $account->id(),
            $command->amount,
            $command->description,
            $command->idempotencyKey,
        ));
    }
}
