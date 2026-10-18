<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Обращения в поддержку (support_ticket)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE support_ticket (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL, name VARCHAR(100) NOT NULL, contact VARCHAR(200) NOT NULL, launcher_version VARCHAR(20) DEFAULT NULL, message CLOB NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, status VARCHAR(10) NOT NULL, admin_note CLOB DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_SUPPORT_STATUS_CREATED ON support_ticket (status, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE support_ticket');
    }
}
