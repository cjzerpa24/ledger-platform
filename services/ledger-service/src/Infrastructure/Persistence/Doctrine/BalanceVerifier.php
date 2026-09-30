<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * Checks the two ledger invariants: each cached balance equals the net of
 * its postings, and each currency's balances sum to zero.
 */
final class BalanceVerifier
{
    public function __construct(private readonly Connection $connection) {}

    public function verify(): BalanceReport
    {
        $mismatches = $this->connection->fetchAllAssociative(
            "SELECT a.id, a.balance_minor,
                    COALESCE(SUM(CASE WHEN p.direction = 'credit' THEN p.amount_minor ELSE -p.amount_minor END), 0) AS computed
             FROM accounts a
             LEFT JOIN postings p ON p.account_id = a.id
             GROUP BY a.id, a.balance_minor
             HAVING a.balance_minor <> COALESCE(SUM(CASE WHEN p.direction = 'credit' THEN p.amount_minor ELSE -p.amount_minor END), 0)
             ORDER BY a.id",
        );
        $imbalances = $this->connection->fetchAllAssociative(
            'SELECT currency, SUM(balance_minor) AS total FROM accounts GROUP BY currency HAVING SUM(balance_minor) <> 0 ORDER BY currency',
        );

        return new BalanceReport(
            array_map(static fn(array $row): array => [
                'accountId' => self::string($row, 'id'),
                'cachedMinor' => self::int($row, 'balance_minor'),
                'computedMinor' => self::int($row, 'computed'),
            ], $mismatches),
            array_map(static fn(array $row): array => [
                'currency' => self::string($row, 'currency'),
                'totalMinor' => self::int($row, 'total'),
            ], $imbalances),
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
