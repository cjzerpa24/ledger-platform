<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924045147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create outbox_messages for transactional event delivery';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE outbox_messages (published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attempts INT NOT NULL, last_error TEXT DEFAULT NULL, id UUID NOT NULL, event_name VARCHAR(100) NOT NULL, event_version INT NOT NULL, aggregate_id UUID NOT NULL, payload JSONB NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_outbox_pending ON outbox_messages (published_at, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE outbox_messages');
    }
}
