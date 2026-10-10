<?php

namespace App\ServerList;

use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class ServerPopulationHistory
{
    public const INTERVAL = 900;
    private const RETENTION = 8 * 86400;

    public function __construct(
        #[Autowire('%app.data_dir%/servers/history')]
        private readonly string $directory,
    ) {
    }

    public static function key(array $server): string
    {
        return (string) ($server['ip'] ?? '') . ':' . (int) ($server['queryPort'] ?? $server['port'] ?? 0);
    }

    public function record(array $servers, int $timestamp): void
    {
        $db = $this->database();
        $slot = intdiv($timestamp, self::INTERVAL) * self::INTERVAL;
        $hour = intdiv($slot, 3600) * 3600;
        $db->beginTransaction();
        try {
            $insert = $db->prepare('INSERT OR IGNORE INTO snapshots (slot) VALUES (?)');
            $insert->execute([$slot]);
            if ($insert->rowCount() === 0) {
                $db->rollBack();
                return;
            }
            $write = $db->prepare('INSERT INTO population (server, hour, samples, players, active, online, capacity)
                VALUES (?, ?, 1, ?, ?, ?, ?)
                ON CONFLICT(server, hour) DO UPDATE SET samples=samples+1, players=players+excluded.players,
                active=active+excluded.active, online=online+excluded.online, capacity=capacity+excluded.capacity');
            $seen = [];
            foreach ($servers as $server) {
                $key = self::key($server);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $online = !empty($server['online']);
                $players = $online ? max(0, (int) ($server['players'] ?? 0)) : 0;
                $write->execute([$key, $hour, $players, (int) ($players > 0), (int) $online, max(0, (int) ($server['maxPlayers'] ?? 0))]);
            }
            $db->prepare('DELETE FROM population WHERE hour < ?')->execute([$hour - self::RETENTION]);
            $db->prepare('DELETE FROM snapshots WHERE slot < ?')->execute([$slot - self::RETENTION]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $summary = $this->summarize($timestamp);
        (new Filesystem())->dumpFile($this->directory . '/summary.json', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function summary(): array
    {
        $path = $this->directory . '/summary.json';
        if (!is_file($path)) return ['updatedAt' => null, 'servers' => []];
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : ['updatedAt' => null, 'servers' => []];
    }

    private function summarize(int $timestamp): array
    {
        // Completed hours only: partial hours must not skew day/night coverage.
        $end = intdiv($timestamp, 3600) * 3600;
        $start = $end - 7 * 86400;
        $startedAt = (int) $this->database()->query('SELECT MIN(slot) FROM snapshots')->fetchColumn();
        $query = $this->database()->prepare('SELECT server, SUM(samples) samples, SUM(players) players,
            SUM(active) active, SUM(online) online, SUM(capacity) capacity,
            SUM(CASE WHEN ((hour / 3600 + 3) % 24) BETWEEN 0 AND 5 THEN samples ELSE 0 END) night_samples,
            SUM(CASE WHEN ((hour / 3600 + 3) % 24) BETWEEN 0 AND 5 THEN players ELSE 0 END) night_players,
            SUM(CASE WHEN ((hour / 3600 + 3) % 24) BETWEEN 10 AND 17 THEN samples ELSE 0 END) day_samples,
            SUM(CASE WHEN ((hour / 3600 + 3) % 24) BETWEEN 10 AND 17 THEN players ELSE 0 END) day_players
            FROM population WHERE hour >= ? AND hour < ? GROUP BY server');
        $query->execute([$start, $end]);
        $servers = [];
        foreach ($query as $row) {
            $samples = (int) $row['samples'];
            $coverage = min(100, round($samples / 672 * 100, 1));
            $ready = $coverage >= 70 && $startedAt <= $start;
            $servers[$row['server']] = [
                'samples' => $samples, 'coverage' => $coverage, 'ready' => $ready,
                'average' => round($row['players'] / $samples, 1),
                'activePercent' => round($row['active'] / $samples * 100, 1),
                'onlinePercent' => round($row['online'] / $samples * 100, 1),
                'occupancy' => $row['capacity'] > 0 ? round($row['players'] / $row['capacity'] * 100, 1) : null,
                'day' => $ready && $row['day_samples'] >= 157 ? round($row['day_players'] / $row['day_samples'], 1) : null,
                'night' => $ready && $row['night_samples'] >= 118 ? round($row['night_players'] / $row['night_samples'], 1) : null,
            ];
        }
        return ['updatedAt' => $timestamp, 'startedAt' => $startedAt, 'periodDays' => 7, 'timezone' => 'Europe/Moscow', 'servers' => $servers];
    }

    public function series(string $key, int $days, int $now): array
    {
        $end = intdiv($now, 3600) * 3600;
        $start = $end - $days * 86400 + 3600;
        $query = $this->database()->prepare('SELECT hour, samples, players, online FROM population WHERE server=? AND hour>=? AND hour<=? ORDER BY hour');
        $query->execute([$key, $start, $end]);
        $observed = [];
        foreach ($query as $row) $observed[(int) $row['hour']] = $row;
        $points = [];
        for ($hour = $start; $hour <= $end; $hour += 3600) {
            $row = $observed[$hour] ?? null;
            $points[] = ['timestamp' => $hour, 'players' => $row ? round($row['players'] / $row['samples'], 1) : null,
                'samples' => $row ? (int) $row['samples'] : 0];
        }
        return $points;
    }

    private function database(): PDO
    {
        (new Filesystem())->mkdir($this->directory);
        $db = new PDO('sqlite:' . $this->directory . '/population.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS snapshots (slot INTEGER PRIMARY KEY);
            CREATE TABLE IF NOT EXISTS population (server TEXT NOT NULL, hour INTEGER NOT NULL, samples INTEGER NOT NULL,
            players INTEGER NOT NULL, active INTEGER NOT NULL, online INTEGER NOT NULL, capacity INTEGER NOT NULL, PRIMARY KEY(server, hour));
            CREATE INDEX IF NOT EXISTS population_hour ON population(hour)');
        return $db;
    }
}
