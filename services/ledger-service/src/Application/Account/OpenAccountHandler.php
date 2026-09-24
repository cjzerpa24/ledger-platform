<?php

declare(strict_types=1);

namespace App\Application\Account;

class OpenAccountHandler
{
    public function __construct(private AccountRepository $accountRepository)
    {
    }

    public function handle(OpenAccountDTO $openAccountDTO): AccountViewDTO
    {
        $account = new Account();
        $account->setName($openAccountDTO->name);
        $account->setDescription($openAccountDTO->description);
        $account->setAccountNumber($openAccountDTO->accountNumber);
        $account->setAccountType($openAccountDTO->accountType);
        $account->setAccountBalance($openAccountDTO->accountBalance);
        $this->accountRepository->create($account);
        return new AccountViewDTO($account->getId(), $account->getName(), $account->getDescription(), $account->getAccountNumber(), $account->getAccountType(), $account->getAccountBalance());
    }
}
