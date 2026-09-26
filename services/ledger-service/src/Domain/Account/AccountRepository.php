<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Account\Exception\AccountNotFound;
use App\Domain\Account\Exception\SettlementAccountMissing;
use Money\Currency;
use Symfony\Component\Uid\Uuid;

interface AccountRepository
{
    public function add(Account $account): void;

    /** @throws AccountNotFound */
    public function get(Uuid $id): Account;

    /**
     * Loads the account with a row lock (SELECT ... FOR UPDATE), refreshing any
     * copy already in memory. Must be called inside a transaction.
     *
     * @throws AccountNotFound
     */
    public function getForUpdate(Uuid $id): Account;

    /** @throws SettlementAccountMissing */
    public function getSettlement(Currency $currency): Account;
}
