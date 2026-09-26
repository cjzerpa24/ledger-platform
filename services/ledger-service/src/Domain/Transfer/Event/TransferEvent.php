<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Event;

use App\Domain\Shared\DomainEvent;
use App\Domain\Shared\MoneyFormatter;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/** Shared shape of the three money-movement events (contract v1). */
abstract readonly class TransferEvent implements DomainEvent
{
    public function __construct(
        public Uuid $transferId,
        public Uuid $journalEntryId,
        public Uuid $sourceAccountId,
        public Uuid $destinationAccountId,
        public Money $amount,
        public ?string $description,
        public \DateTimeImmutable $completedAt,
    ) {}

    public function eventVersion(): int
    {
        return 1;
    }

    public function aggregateId(): Uuid
    {
        return $this->transferId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function payload(): array
    {
        return [
            'transferId' => $this->transferId->toRfc4122(),
            'journalEntryId' => $this->journalEntryId->toRfc4122(),
            'sourceAccountId' => $this->sourceAccountId->toRfc4122(),
            'destinationAccountId' => $this->destinationAccountId->toRfc4122(),
            'amount' => MoneyFormatter::format($this->amount),
            'amountMinor' => (int) $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'description' => $this->description,
            'completedAt' => $this->completedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
