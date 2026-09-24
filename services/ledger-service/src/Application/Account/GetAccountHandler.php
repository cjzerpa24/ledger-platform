<?php

declare(strict_types=1);

namespace App\Application\Account;

class GetAccountHandler
{
    public function __construct(private AccountRepository $accountRepository)
    {
    }

    public function handle(string $id): AccountViewDTO
    {
        $account = $this->accountRepository->findById($id);
        return new AccountViewDTO($account->getId(), $account->getName(), $account->getDescription(), $account->getAccountNumber(), $account->getAccountType(), $account->getAccountBalance());
    }
}
