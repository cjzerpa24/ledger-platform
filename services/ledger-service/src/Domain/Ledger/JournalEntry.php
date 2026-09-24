<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exception\UnbalancedJournalEntry;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * An immutable, balanced accounting record: total debits equal total credits,
 * in a single currency. Created only through record().
 */
#[ORM\Entity]
#[ORM\Table(name: 'journal_entries')]
#[ORM\Index(name: 'idx_journal_entries_source', columns: ['source_type', 'source_id'])]
class JournalEntry
{
    /** @var Collection<int, Posting> */
    #[ORM\OneToMany(targetEntity: Posting::class, mappedBy: 'journalEntry', cascade: ['persist'])]
    private Collection $postings;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $description,
        #[ORM\Column(length: 30)]
        private string $sourceType,
        #[ORM\Column(type: 'uuid')]
        private Uuid $sourceId,
    ) {
        $this->postings = new ArrayCollection();
    }

    /** @param list<PostingLine> $lines */
    public static function record(
        Uuid $id,
        \DateTimeImmutable $occurredAt,
        ?string $description,
        string $sourceType,
        Uuid $sourceId,
        array $lines,
    ): self {
        self::assertBalanced($lines);

        $entry = new self($id, $occurredAt, $description, $sourceType, $sourceId);
        foreach ($lines as $line) {
            $entry->postings->add(new Posting(Uuid::v7(), $entry, $line, $occurredAt));
        }

        return $entry;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function sourceId(): Uuid
    {
        return $this->sourceId;
    }

    /** @return list<Posting> */
    public function postings(): array
    {
        return array_values($this->postings->toArray());
    }

    /** @param list<PostingLine> $lines */
    private static function assertBalanced(array $lines): void
    {
        if (\count($lines) < 2) {
            throw UnbalancedJournalEntry::tooFewLines(\count($lines));
        }

        $currency = $lines[0]->amount->getCurrency();
        $debits = new Money(0, $currency);
        $credits = new Money(0, $currency);

        foreach ($lines as $line) {
            if (!$line->amount->getCurrency()->equals($currency) || !$line->balanceAfter->getCurrency()->equals($currency)) {
                throw UnbalancedJournalEntry::mixedCurrencies();
            }
            if (!$line->amount->isPositive()) {
                throw UnbalancedJournalEntry::nonPositiveAmount();
            }

            if (Direction::DEBIT === $line->direction) {
                $debits = $debits->add($line->amount);
            } else {
                $credits = $credits->add($line->amount);
            }
        }

        if (!$debits->equals($credits)) {
            throw UnbalancedJournalEntry::debitsNotEqualCredits($debits, $credits);
        }
    }
}
