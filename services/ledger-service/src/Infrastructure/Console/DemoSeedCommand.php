<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Account\GetAccountHandler;
use App\Application\Account\OpenAccountCommand;
use App\Application\Account\OpenAccountHandler;
use App\Application\Transfer\RecordDepositCommand;
use App\Application\Transfer\RecordDepositHandler;
use App\Application\Transfer\TransferMoneyCommand;
use App\Application\Transfer\TransferMoneyHandler;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'ledger:demo:seed', description: 'Create demo accounts, deposits and a transfer (runs once)')]
final class DemoSeedCommand
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OpenAccountHandler $openAccount,
        private readonly GetAccountHandler $getAccount,
        private readonly RecordDepositHandler $recordDeposit,
        private readonly TransferMoneyHandler $transferMoney,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $customers = $this->connection->fetchOne("SELECT COUNT(*) FROM accounts WHERE type = 'customer'");
        \assert(is_numeric($customers));
        if ((int) $customers > 0) {
            $io->note('Customer accounts already exist; skipping the demo seed.');

            return Command::SUCCESS;
        }

        $alice = $this->open('Alice (demo)', 'USD');
        $bob = $this->open('Bob (demo)', 'USD');
        $amani = $this->open('Amani (demo)', 'TZS');

        $this->recordDeposit->handle(new RecordDepositCommand($alice, '1000.00', 'Opening deposit', 'demo-seed-deposit-alice'));
        $this->recordDeposit->handle(new RecordDepositCommand($amani, '250000.00', 'Opening deposit', 'demo-seed-deposit-amani'));
        $this->transferMoney->handle(new TransferMoneyCommand($alice, $bob, '125.50', 'Dinner split', 'demo-seed-transfer-1'));

        $io->table(['Account', 'Id', 'Balance'], array_map(function (Uuid $id): array {
            $view = $this->getAccount->handle($id);

            return [$view->name, $view->id, $view->balance . ' ' . $view->currency];
        }, [$alice, $bob, $amani]));
        $io->success('Demo data created.');

        return Command::SUCCESS;
    }

    private function open(string $name, string $currency): Uuid
    {
        return Uuid::fromString($this->openAccount->handle(new OpenAccountCommand($name, $currency))->id);
    }
}
