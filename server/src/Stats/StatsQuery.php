<?php

namespace App\Stats;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Цифры и графики для дашборда админки.
 */
final class StatsQuery
{
    /** Лаунчер считается онлайн, если присылал событие за последние N минут (heartbeat раз в 5 минут). */
    public const ONLINE_WINDOW_MINUTES = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function onlineNow(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM launcher_install WHERE last_seen_at >= ?',
            [$this->format($this->clock->now()->modify('-' . self::ONLINE_WINDOW_MINUTES . ' minutes'))],
        );
    }

    public function totalInstalls(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM launcher_install');
    }

    /** Уникальные IP, запускавшие лаунчер сегодня. */
    public function uniqueIpsToday(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT ip) FROM daily_activity WHERE day = ?', [$this->today()]);
    }

    public function playsToday(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM play_event WHERE created_at >= ?', [$this->today() . ' 00:00:00']);
    }

    /**
     * По дням за последние $days дней: уникальные IP, установки и нажатия «Играть».
     *
     * @return list<array{day: string, uniqueIps: int, launchers: int, plays: int}>
     */
    public function daily(int $days = 30): array
    {
        $from = $this->clock->now()->setTime(0, 0)->modify('-' . ($days - 1) . ' days');

        $activity = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT day, COUNT(DISTINCT ip) AS ips, COUNT(DISTINCT launcher_id) AS launchers FROM daily_activity WHERE day >= ? GROUP BY day',
            [$from->format('Y-m-d')],
        ) as $row) {
            $activity[$row['day']] = $row;
        }

        $plays = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT SUBSTR(created_at, 1, 10) AS day, COUNT(*) AS plays FROM play_event WHERE created_at >= ? GROUP BY SUBSTR(created_at, 1, 10)',
            [$this->format($from)],
        ) as $row) {
            $plays[$row['day']] = (int) $row['plays'];
        }

        $result = [];
        for ($day = $from, $i = 0; $i < $days; ++$i, $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $result[] = [
                'day' => $key,
                'uniqueIps' => (int) ($activity[$key]['ips'] ?? 0),
                'launchers' => (int) ($activity[$key]['launchers'] ?? 0),
                'plays' => $plays[$key] ?? 0,
            ];
        }

        return $result;
    }

    /** @return list<array{serverName: string, serverAddress: string, plays: int, players: int}> */
    public function topServers(int $days = 7, int $limit = 10): array
    {
        return array_map(static fn (array $row): array => [
            'serverName' => (string) $row['server_name'],
            'serverAddress' => (string) $row['server_address'],
            'plays' => (int) $row['plays'],
            'players' => (int) $row['players'],
        ], $this->db->fetchAllAssociative(
            'SELECT server_name, server_address, COUNT(*) AS plays, COUNT(DISTINCT ip) AS players
             FROM play_event WHERE created_at >= ?
             GROUP BY server_address, server_name ORDER BY plays DESC LIMIT ' . (int) $limit,
            [$this->format($this->clock->now()->modify("-{$days} days"))],
        ));
    }

    /** @return list<array{version: string, installs: int}> активные за 30 дней установки по версиям */
    public function versions(): array
    {
        return array_map(static fn (array $row): array => ['version' => (string) $row['version'], 'installs' => (int) $row['installs']], $this->db->fetchAllAssociative(
            'SELECT version, COUNT(*) AS installs FROM launcher_install WHERE last_seen_at >= ? GROUP BY version ORDER BY installs DESC',
            [$this->format($this->clock->now()->modify('-30 days'))],
        ));
    }

    /** Удаляет статистику по IP старше $days дней. */
    public function prune(int $days): int
    {
        $border = $this->clock->now()->setTime(0, 0)->modify("-{$days} days");

        return (int) $this->db->executeStatement('DELETE FROM daily_activity WHERE day < ?', [$border->format('Y-m-d')])
            + (int) $this->db->executeStatement('DELETE FROM play_event WHERE created_at < ?', [$this->format($border)]);
    }

    private function today(): string
    {
        return $this->clock->now()->format('Y-m-d');
    }

    private function format(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s');
    }
}
