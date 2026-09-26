<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Infrastructure\Messaging\OutboxRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'ledger:outbox:relay', description: 'Publish pending outbox events to the ledger_events transport')]
final class OutboxRelayCommand
{
    private const string LOCK_NAME = 'ledger-outbox-relay';

    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly LockFactory $lockFactory,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Maximum events per batch')]
        int $batch = 100,
        #[Option(description: 'Keep polling instead of exiting after one batch')]
        bool $loop = false,
        #[Option(description: 'Milliseconds to wait when the outbox is empty')]
        int $sleep = 1000,
    ): int {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME);
        // In loop mode wait as a hot standby; a one-shot run just yields.
        if (!$lock->acquire($loop)) {
            $io->note('Another relay holds the lock; exiting.');

            return Command::SUCCESS;
        }

        try {
            do {
                $result = $this->relay->relay($batch);
                if ($result->published + $result->failed > 0 || !$loop) {
                    $io->writeln(\sprintf('Published %d, failed %d', $result->published, $result->failed));
                }
                if ($loop && 0 === $result->published) {
                    usleep(max(0, $sleep) * 1000);
                }
            } while ($loop);
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
