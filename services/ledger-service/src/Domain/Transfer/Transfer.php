<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Shared\Exception\InvalidAmount;
use App\Domain\Shared\MoneyFormatter;
use App\Domain\Shared\RecordsEvents;
use App\Domain\Transfer\Event\DepositRecorded;
use App\Domain\Transfer\Event\TransferCompleted;
use App\Domain\Transfer\Event\WithdrawalRecorded;
use App\Domain\Transfer\Exception\SameAccountTransfer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * The business request to move money, linked to the journal entry that
 * records it. The idempotency key is unique, so a retried request can be
 * answered with the original result.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transfers')]
class Transfer
{
    use RecordsEvents;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(length: 20, enumType: TransferKind::class)]
        private TransferKind $kind,
        #[ORM\Column(type: 'uuid')]
        private Uuid $sourceAccountId,
        #[ORM\Column(type: 'uuid')]
        private Uuid $destinationAccountId,
        #[ORM\Column(type: Types::BIGINT)]
        private int $amountMinor,
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $description,
        #[ORM\Column(length: 255, unique: true)]
        private string $idempotencyKey,
        #[ORM\Column(length: 64)]
        private string $requestHash,
        #[ORM\Column(length: 20, enumType: TransferStatus::class)]
        private TransferStatus $status,
        #[ORM\Column(type: 'uuid')]
        private Uuid $journalEntryId,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {}

    public static function complete(
        Uuid $id,
        TransferKind $kind,
        Uuid $sourceAccountId,
        Uuid $destinationAccountId,
        Money $amount,
        ?string $description,
        string $idempotencyKey,
        string $requestHash,
        Uuid $journalEntryId,
        \DateTimeImmutable $now,
    ): self {
        if ($sourceAccountId->equals($destinationAccountId)) {
            throw SameAccountTransfer::forAccount($sourceAccountId);
        }
        if (!$amount->isPositive()) {
            throw InvalidAmount::notPositive(MoneyFormatter::format($amount));
        }

        $transfer = new self(
            $id,
            $kind,
            $sourceAccountId,
            $destinationAccountId,
            (int) $amount->getAmount(),
            $amount->getCurrency()->getCode(),
            $description,
            $idempotencyKey,
            $requestHash,
            TransferStatus::COMPLETED,
            $journalEntryId,
            $now,
        );

        $eventArgs = [$id, $journalEntryId, $sourceAccountId, $destinationAccountId, $amount, $description, $now];
        $transfer->record(match ($kind) {
            TransferKind::INTERNAL => new TransferCompleted(...$eventArgs),
            TransferKind::DEPOSIT => new DepositRecorded(...$eventArgs),
            TransferKind::WITHDRAWAL => new WithdrawalRecorded(...$eventArgs),
        });

        return $transfer;
    }

    public function matchesRequest(string $requestHash): bool
    {
        return hash_equals($this->requestHash, $requestHash);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function kind(): TransferKind
    {
        return $this->kind;
    }

    public function sourceAccountId(): Uuid
    {
        return $this->sourceAccountId;
    }

    public function destinationAccountId(): Uuid
    {
        return $this->destinationAccountId;
    }

    public function amount(): Money
    {
        \assert('' !== $this->currency);

        return new Money($this->amountMinor, new Currency($this->currency));
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function status(): TransferStatus
    {
        return $this->status;
    }

    public function journalEntryId(): Uuid
    {
        return $this->journalEntryId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
