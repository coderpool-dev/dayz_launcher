<?php

namespace App\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TrafficMigrationTest extends TestCase
{
    public function testOldCountersArePreservedAsUnverifiedHistory(): void
    {
        require_once __DIR__ . '/../migrations/Version20261016120000.php';
        require_once __DIR__ . '/../migrations/Version20261017210000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $old = new \DoctrineMigrations\Version20261016120000($db, new NullLogger());
        $old->up(new Schema());
        foreach ($old->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $db->insert('traffic_event', ['created_at' => '2026-10-16 10:00:00', 'type' => 'visit', 'source' => 'direct', 'landing_path' => '/', 'ip' => '192.0.2.1']);
        $migration = new \DoctrineMigrations\Version20261017210000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $row = $db->fetchAssociative('SELECT * FROM traffic_event');
        self::assertSame('legacy', $row['verification']);
        self::assertNull($row['visitor_id']);
        self::assertNull($row['confirmed_at']);
        self::assertSame('2026-10-16 10:00:00', $row['created_at']);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM traffic_event'));
        $db->close();
    }
}
