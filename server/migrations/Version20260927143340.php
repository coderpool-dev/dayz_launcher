<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927143340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(64) NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AD8A54A9F85E0677 ON admin_user (username)');
        $this->addSql('CREATE TABLE daily_activity (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day DATE NOT NULL, launcher_id VARCHAR(36) NOT NULL, ip VARCHAR(45) NOT NULL)');
        $this->addSql('CREATE INDEX IDX_167BDE8EE5A02990 ON daily_activity (day)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_167BDE8EE5A029902724B909A5E3B32D ON daily_activity (day, launcher_id, ip)');
        $this->addSql('CREATE TABLE launcher_install (launcher_id VARCHAR(36) NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_ip VARCHAR(45) NOT NULL, version VARCHAR(20) NOT NULL, start_count INTEGER NOT NULL, play_count INTEGER NOT NULL, PRIMARY KEY (launcher_id))');
        $this->addSql('CREATE INDEX IDX_7124EE58B81C492A ON launcher_install (last_seen_at)');
        $this->addSql('CREATE TABLE launcher_release (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, version VARCHAR(20) NOT NULL, notes CLOB DEFAULT NULL, file_name VARCHAR(255) NOT NULL, file_size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, published BOOLEAN NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C0A9503CBF1CD3C3 ON launcher_release (version)');
        $this->addSql('CREATE TABLE play_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_at DATETIME NOT NULL, launcher_id VARCHAR(36) NOT NULL, ip VARCHAR(45) NOT NULL, version VARCHAR(20) NOT NULL, server_id VARCHAR(20) NOT NULL, server_name VARCHAR(255) NOT NULL, server_address VARCHAR(64) NOT NULL)');
        $this->addSql('CREATE INDEX IDX_CA71ED1B8B8E8428 ON play_event (created_at)');
        $this->addSql('CREATE TABLE sponsor_server (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(120) NOT NULL, ip VARCHAR(45) NOT NULL, port INTEGER NOT NULL, priority INTEGER NOT NULL, active BOOLEAN NOT NULL, active_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE daily_activity');
        $this->addSql('DROP TABLE launcher_install');
        $this->addSql('DROP TABLE launcher_release');
        $this->addSql('DROP TABLE play_event');
        $this->addSql('DROP TABLE sponsor_server');
    }
}
