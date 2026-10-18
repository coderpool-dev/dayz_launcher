<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate confirmed browser traffic from unverified historical requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE traffic_event ADD COLUMN verification VARCHAR(16) DEFAULT 'legacy' NOT NULL");
        $this->addSql('ALTER TABLE traffic_event ADD COLUMN visitor_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE traffic_event ADD COLUMN visit_id INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE traffic_event ADD COLUMN user_agent VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE traffic_event ADD COLUMN confirmed_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX traffic_verification_period ON traffic_event (verification, type, created_at)');
        $this->addSql('CREATE INDEX traffic_visit_type ON traffic_event (visit_id, type)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Keep the verification history and anonymous visitor attribution.');
    }
}
