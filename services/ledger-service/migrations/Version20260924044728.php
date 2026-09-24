<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reference data: one settlement account per supported currency. Deposits and
 * withdrawals post against these, so they must exist before any money moves.
 * The UUID suffix is the currency's ISO-4217 numeric code.
 */
final class Version20260924044728 extends AbstractMigration
{
    public const array SETTLEMENT_ACCOUNTS = [
        'USD' => '0199a000-0000-7000-8000-000000000840',
        'EUR' => '0199a000-0000-7000-8000-000000000978',
        'GBP' => '0199a000-0000-7000-8000-000000000826',
        'TZS' => '0199a000-0000-7000-8000-000000000834',
        'JPY' => '0199a000-0000-7000-8000-000000000392',
    ];

    public function getDescription(): string
    {
        return 'Seed one settlement account per supported currency';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SETTLEMENT_ACCOUNTS as $currency => $id) {
            $this->addSql(
                "INSERT INTO accounts (id, name, type, currency, status, balance_minor, version, created_at, updated_at)
                 VALUES (:id, :name, 'settlement', :currency, 'active', 0, 1, NOW(), NOW())",
                ['id' => $id, 'name' => 'Settlement ' . $currency, 'currency' => $currency],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM accounts WHERE type = 'settlement'");
    }
}
