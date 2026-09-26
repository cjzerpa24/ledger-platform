<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Shared\MoneyFormatter;
use App\Domain\Transfer\Transfer;

final readonly class TransferView
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $sourceAccountId,
        public string $destinationAccountId,
        public string $amount,
        public string $currency,
        public ?string $description,
        public string $status,
        public string $journalEntryId,
        public string $createdAt,
    ) {}

    public static function fromTransfer(Transfer $transfer): self
    {
        return new self(
            $transfer->id()->toRfc4122(),
            $transfer->kind()->value,
            $transfer->sourceAccountId()->toRfc4122(),
            $transfer->destinationAccountId()->toRfc4122(),
            MoneyFormatter::format($transfer->amount()),
            $transfer->amount()->getCurrency()->getCode(),
            $transfer->description(),
            $transfer->status()->value,
            $transfer->journalEntryId()->toRfc4122(),
            $transfer->createdAt()->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
