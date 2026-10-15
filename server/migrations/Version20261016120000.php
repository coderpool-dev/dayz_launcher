<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Источники визитов и скачиваний (traffic_event)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE traffic_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL, type VARCHAR(10) NOT NULL, source VARCHAR(32) NOT NULL, referrer VARCHAR(1024) DEFAULT NULL, referrer_host VARCHAR(255) DEFAULT NULL, landing_path VARCHAR(512) NOT NULL, utm_source VARCHAR(100) DEFAULT NULL, utm_campaign VARCHAR(100) DEFAULT NULL, ip VARCHAR(45) NOT NULL)');
        $this->addSql('CREATE INDEX IDX_TRAFFIC_CREATED ON traffic_event (created_at)');
        $this->addSql('CREATE INDEX IDX_TRAFFIC_TYPE_CREATED ON traffic_event (type, created_at)');
        $this->addSql('CREATE INDEX IDX_TRAFFIC_IP ON traffic_event (ip)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE traffic_event');
    }
}
