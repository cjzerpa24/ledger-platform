<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\AccountRepository;
use Symfony\Component\Uid\Uuid;

final class GetAccountHandler
{
    public function __construct(private readonly AccountRepository $accounts) {}

    public function handle(Uuid $id): AccountView
    {
        return AccountView::fromAccount($this->accounts->get($id));
    }
}
