<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Ledger;

use App\Domain\Ledger\Direction;
use App\Domain\Ledger\Exception\UnbalancedJournalEntry;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\PostingLine;
use App\Domain\Shared\SupportedCurrencies;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class JournalEntryTest extends TestCase
{
    public function testRecordsBalancedEntryWithOnePostingPerLine(): void
    {
        $from = Uuid::v7();
        $to = Uuid::v7();
        $at = new \DateTimeImmutable('2026-09-24 10:00:00');

        $entry = JournalEntry::record(Uuid::v7(), $at, 'Rent', 'transfer', Uuid::v7(), [
            new PostingLine($from, Direction::DEBIT, $this->usd(5000), $this->usd(1000)),
            new PostingLine($to, Direction::CREDIT, $this->usd(5000), $this->usd(5000)),
        ]);

        $postings = $entry->postings();
        self::assertCount(2, $postings);
        self::assertTrue($from->equals($postings[0]->accountId()));
        self::assertSame(Direction::DEBIT, $postings[0]->direction());
        self::assertTrue($postings[0]->amount()->equals($this->usd(5000)));
        self::assertTrue($postings[0]->balanceAfter()->equals($this->usd(1000)));
        self::assertSame($entry, $postings[1]->journalEntry());
        self::assertEquals($at, $postings[1]->occurredAt());
        self::assertSame('Rent', $entry->description());
    }

    public function testRejectsDebitsNotEqualToCredits(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);
        $this->expectExceptionMessage('debits 50.00 do not equal credits 49.99');

        $this->record([
            new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(5000), $this->usd(0)),
            new PostingLine(Uuid::v7(), Direction::CREDIT, $this->usd(4999), $this->usd(0)),
        ]);
    }

    public function testRejectsSingleLine(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        $this->record([new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(1), $this->usd(0))]);
    }

    public function testRejectsMixedCurrencies(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        $eur = new Money(100, SupportedCurrencies::get('EUR'));
        $this->record([
            new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(100), $this->usd(0)),
            new PostingLine(Uuid::v7(), Direction::CREDIT, $eur, $eur),
        ]);
    }

    public function testRejectsNonPositiveAmounts(): void
    {
        $this->expectException(UnbalancedJournalEntry::class);

        $this->record([
            new PostingLine(Uuid::v7(), Direction::DEBIT, $this->usd(0), $this->usd(0)),
            new PostingLine(Uuid::v7(), Direction::CREDIT, $this->usd(0), $this->usd(0)),
        ]);
    }

    /** @param list<PostingLine> $lines */
    private function record(array $lines): JournalEntry
    {
        return JournalEntry::record(Uuid::v7(), new \DateTimeImmutable(), null, 'transfer', Uuid::v7(), $lines);
    }

    private function usd(int $minor): Money
    {
        return new Money($minor, SupportedCurrencies::get('USD'));
    }
}
