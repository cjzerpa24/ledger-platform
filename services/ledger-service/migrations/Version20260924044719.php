<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924044719 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts, journal_entries, postings and transfers';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE accounts (balance_minor BIGINT NOT NULL, version INT DEFAULT 1 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_accounts_type_currency ON accounts (type, currency)');
        $this->addSql('CREATE TABLE journal_entries (id UUID NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, description VARCHAR(255) DEFAULT NULL, source_type VARCHAR(30) NOT NULL, source_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_journal_entries_source ON journal_entries (source_type, source_id)');
        $this->addSql('CREATE TABLE postings (id UUID NOT NULL, account_id UUID NOT NULL, direction VARCHAR(6) NOT NULL, amount_minor BIGINT NOT NULL, balance_after_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, journal_entry_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_postings_account_time ON postings (account_id, occurred_at, id)');
        $this->addSql('CREATE INDEX IDX_D2BFC8D06A86E4FB ON postings (journal_entry_id)');
        $this->addSql('CREATE TABLE transfers (id UUID NOT NULL, kind VARCHAR(20) NOT NULL, source_account_id UUID NOT NULL, destination_account_id UUID NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, description VARCHAR(255) DEFAULT NULL, idempotency_key VARCHAR(255) NOT NULL, request_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, journal_entry_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_802A39187FD1C147 ON transfers (idempotency_key)');
        $this->addSql('ALTER TABLE postings ADD CONSTRAINT FK_D2BFC8D06A86E4FB FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE postings DROP CONSTRAINT FK_D2BFC8D06A86E4FB');
        $this->addSql('DROP TABLE accounts');
        $this->addSql('DROP TABLE journal_entries');
        $this->addSql('DROP TABLE postings');
        $this->addSql('DROP TABLE transfers');
    }
}
