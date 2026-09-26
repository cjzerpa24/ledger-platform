<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\StatementLine;
use App\Application\Port\StatementReader;
use App\Domain\Ledger\Direction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

final class DbalStatementReader implements StatementReader
{
    public function __construct(private readonly Connection $connection) {}

    public function openingBalanceMinor(Uuid $accountId, \DateTimeImmutable $before): int
    {
        $balance = $this->connection->fetchOne(
            'SELECT balance_after_minor FROM postings
             WHERE account_id = :account AND occurred_at < :before
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1',
            ['account' => $accountId->toRfc4122(), 'before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );

        return is_numeric($balance) ? (int) $balance : 0;
    }

    public function lines(Uuid $accountId, \DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.occurred_at, p.direction, p.amount_minor, p.balance_after_minor, j.description, j.source_id
             FROM postings p
             JOIN journal_entries j ON j.id = p.journal_entry_id
             WHERE p.account_id = :account AND p.occurred_at >= :from AND p.occurred_at < :to
             ORDER BY p.occurred_at, p.id',
            ['account' => $accountId->toRfc4122(), 'from' => $from, 'to' => $toExclusive],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(self::toLine(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function toLine(array $row): StatementLine
    {
        return new StatementLine(
            self::string($row, 'id'),
            new \DateTimeImmutable(self::string($row, 'occurred_at')),
            null === $row['description'] ? null : self::string($row, 'description'),
            self::string($row, 'source_id'),
            Direction::from(self::string($row, 'direction')),
            self::int($row, 'amount_minor'),
            self::int($row, 'balance_after_minor'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column];
        \assert(\is_string($value));

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column];
        \assert(is_numeric($value));

        return (int) $value;
    }
}
