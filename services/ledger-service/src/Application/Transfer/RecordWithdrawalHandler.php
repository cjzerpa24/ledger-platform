<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Account\AccountRepository;
use App\Domain\Transfer\TransferKind;

/** Money leaving the ledger: customer → settlement. */
final class RecordWithdrawalHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PostTransferService $poster,
    ) {}

    public function handle(RecordWithdrawalCommand $command): PostTransferResult
    {
        $account = $this->accounts->get($command->accountId);
        $settlement = $this->accounts->getSettlement($account->currency());

        return $this->poster->post(new PostTransferCommand(
            TransferKind::WITHDRAWAL,
            $account->id(),
            $settlement->id(),
            $command->amount,
            $command->description,
            $command->idempotencyKey,
        ));
    }
}
