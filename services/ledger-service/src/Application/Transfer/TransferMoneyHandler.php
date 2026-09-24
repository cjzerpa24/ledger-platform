<?php

declare(strict_types=1);

namespace App\Application\Transfer;

class TransferMoneyHandler
{
    public function __construct(private TransferRepository $transferRepository)
    {
    }

    public function handle(TransferMoneyDTO $transferMoneyDTO): void
    {
        $transfer = new Transfer();
        $transfer->setFromAccountId($transferMoneyDTO->fromAccountId);
        $transfer->setToAccountId($transferMoneyDTO->toAccountId);
        $transfer->setAmount($transferMoneyDTO->amount);
        $transfer->setDescription($transferMoneyDTO->description);
        $transfer->setStatus(TransferStatus::PENDING);
        $this->transferRepository->create($transfer);
    }
}
