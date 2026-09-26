<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Port\EventPublisher;
use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\SupportedCurrencies;
use App\Application\Port\Clock;
use Symfony\Component\Uid\Uuid;

final class OpenAccountHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly TransactionManager $transactions,
        private readonly EventPublisher $events,
        private readonly Clock $clock,
    ) {}

    public function handle(OpenAccountCommand $command): AccountView
    {
        $currency = SupportedCurrencies::get($command->currency);

        $account = $this->transactions->transactional(function () use ($command, $currency): Account {
            $account = Account::openCustomer(Uuid::v7(), trim($command->name), $currency, $this->clock->now());
            $this->accounts->add($account);
            foreach ($account->pullEvents() as $event) {
                $this->events->publish($event);
            }

            return $account;
        });

        return AccountView::fromAccount($account);
    }
}
