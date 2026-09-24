<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * One immutable line of a journal entry. Stores the account balance after this
 * posting, so statements read running balances instead of recomputing them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'postings')]
#[ORM\Index(name: 'idx_postings_account_time', columns: ['account_id', 'occurred_at', 'id'])]
class Posting
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: JournalEntry::class, inversedBy: 'postings')]
    #[ORM\JoinColumn(nullable: false)]
    private JournalEntry $journalEntry;

    #[ORM\Column(type: 'uuid')]
    private Uuid $accountId;

    #[ORM\Column(length: 6, enumType: Direction::class)]
    private Direction $direction;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amountMinor;

    #[ORM\Column(type: Types::BIGINT)]
    private int $balanceAfterMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @internal Created only by JournalEntry::record() */
    public function __construct(Uuid $id, JournalEntry $journalEntry, PostingLine $line, \DateTimeImmutable $occurredAt)
    {
        $this->id = $id;
        $this->journalEntry = $journalEntry;
        $this->accountId = $line->accountId;
        $this->direction = $line->direction;
        $this->amountMinor = (int) $line->amount->getAmount();
        $this->balanceAfterMinor = (int) $line->balanceAfter->getAmount();
        $this->currency = $line->amount->getCurrency()->getCode();
        $this->occurredAt = $occurredAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function journalEntry(): JournalEntry
    {
        return $this->journalEntry;
    }

    public function accountId(): Uuid
    {
        return $this->accountId;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    public function amount(): Money
    {
        return new Money($this->amountMinor, $this->currency());
    }

    public function balanceAfter(): Money
    {
        return new Money($this->balanceAfterMinor, $this->currency());
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    private function currency(): Currency
    {
        \assert('' !== $this->currency);

        return new Currency($this->currency);
    }
}
