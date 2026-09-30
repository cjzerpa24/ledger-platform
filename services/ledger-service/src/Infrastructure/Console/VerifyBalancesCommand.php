<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Infrastructure\Persistence\Doctrine\BalanceVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ledger:verify-balances', description: 'Check cached balances against postings and per-currency zero sum')]
final class VerifyBalancesCommand
{
    public function __construct(private readonly BalanceVerifier $verifier) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $report = $this->verifier->verify();
        if ($report->isClean()) {
            $io->success('All balances match their postings and every currency sums to zero.');

            return Command::SUCCESS;
        }

        if ([] !== $report->accountMismatches) {
            $io->error('Cached balances that differ from their postings:');
            $io->table(['Account', 'Cached (minor)', 'From postings (minor)'], array_map(
                static fn(array $row): array => [$row['accountId'], $row['cachedMinor'], $row['computedMinor']],
                $report->accountMismatches,
            ));
        }
        if ([] !== $report->currencyImbalances) {
            $io->error('Currencies whose balances do not sum to zero:');
            $io->table(['Currency', 'Total (minor)'], array_map(
                static fn(array $row): array => [$row['currency'], $row['totalMinor']],
                $report->currencyImbalances,
            ));
        }

        return Command::FAILURE;
    }
}
