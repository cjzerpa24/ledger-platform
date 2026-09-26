<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Application\Port\DuplicateRecord;
use App\Application\Port\EventPublisher;
use App\Application\Port\TransactionManager;
use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Ledger\Direction;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\JournalEntryRepository;
use App\Domain\Ledger\PostingLine;
use App\Domain\Shared\Exception\CurrencyMismatch;
use App\Domain\Shared\MoneyParser;
use App\Domain\Transfer\Exception\IdempotencyConflict;
use App\Domain\Transfer\Exception\SameAccountTransfer;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferRepository;
use App\Application\Port\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Posts every kind of money movement as one balanced journal entry, in one
 * transaction, with both accounts row-locked in a fixed order.
 */
final class PostTransferService
{
    private const string SOURCE_TYPE = 'transfer';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly JournalEntryRepository $journalEntries,
        private readonly TransferRepository $transfers,
        private readonly TransactionManager $transactions,
        private readonly EventPublisher $events,
        private readonly Clock $clock,
    ) {}

    public function post(PostTransferCommand $command): PostTransferResult
    {
        $existing = $this->transfers->findByIdempotencyKey($command->idempotencyKey);
        if (null !== $existing) {
            return $this->replay($existing, $command);
        }

        try {
            $transfer = $this->transactions->transactional(fn(): Transfer => $this->execute($command));
        } catch (DuplicateRecord $duplicate) {
            // A concurrent request with the same key committed first.
            $existing = $this->transfers->findByIdempotencyKey($command->idempotencyKey);
            if (null === $existing) {
                throw $duplicate;
            }

            return $this->replay($existing, $command);
        }

        return new PostTransferResult(TransferView::fromTransfer($transfer), false);
    }

    private function execute(PostTransferCommand $command): Transfer
    {
        if ($command->sourceAccountId->equals($command->destinationAccountId)) {
            throw SameAccountTransfer::forAccount($command->sourceAccountId);
        }

        [$source, $destination] = $this->lockInOrder($command->sourceAccountId, $command->destinationAccountId);
        if (!$source->currency()->equals($destination->currency())) {
            throw CurrencyMismatch::between($source->currency(), $destination->currency());
        }

        $amount = MoneyParser::parsePositive($command->amount, $source->currency());
        $now = $this->clock->now();

        $sourceBalance = $source->applyPosting(Direction::DEBIT, $amount, $now);
        $destinationBalance = $destination->applyPosting(Direction::CREDIT, $amount, $now);

        $transferId = Uuid::v7();
        $journalEntryId = Uuid::v7();
        $entry = JournalEntry::record($journalEntryId, $now, $command->description, self::SOURCE_TYPE, $transferId, [
            new PostingLine($source->id(), Direction::DEBIT, $amount, $sourceBalance),
            new PostingLine($destination->id(), Direction::CREDIT, $amount, $destinationBalance),
        ]);
        $transfer = Transfer::complete(
            $transferId,
            $command->kind,
            $source->id(),
            $destination->id(),
            $amount,
            $command->description,
            $command->idempotencyKey,
            $command->requestHash,
            $journalEntryId,
            $now,
        );

        $this->journalEntries->add($entry);
        $this->transfers->add($transfer);
        foreach ([...$transfer->pullEvents(), ...$source->pullEvents(), ...$destination->pullEvents()] as $event) {
            $this->events->publish($event);
        }

        return $transfer;
    }

    /**
     * Locks both rows in UUID order so opposite-direction transfers between
     * the same pair cannot deadlock.
     *
     * @return array{Account, Account} [source, destination]
     */
    private function lockInOrder(Uuid $sourceId, Uuid $destinationId): array
    {
        if (strcmp($sourceId->toRfc4122(), $destinationId->toRfc4122()) < 0) {
            $source = $this->accounts->getForUpdate($sourceId);
            $destination = $this->accounts->getForUpdate($destinationId);
        } else {
            $destination = $this->accounts->getForUpdate($destinationId);
            $source = $this->accounts->getForUpdate($sourceId);
        }

        return [$source, $destination];
    }

    private function replay(Transfer $existing, PostTransferCommand $command): PostTransferResult
    {
        if (!$existing->matchesRequest($command->requestHash)) {
            throw IdempotencyConflict::forKey($command->idempotencyKey);
        }

        return new PostTransferResult(TransferView::fromTransfer($existing), true);
    }
}
