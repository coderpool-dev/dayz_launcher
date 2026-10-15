<?php

namespace App\Traffic;

use App\Entity\TrafficEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/** Источники для дашборда: визиты, скачивания и установки по источникам. */
final class TrafficReport
{
    /** Установка относится к скачиванию с того же IP не раньше, чем за столько до первого запуска. */
    private const INSTALL_MATCH_HOURS = 48;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function build(int $days): array
    {
        $days = max(1, min(365, $days));
        $from = $this->clock->now()->setTime(0, 0)->modify('-' . ($days - 1) . ' days')->format('Y-m-d H:i:s');

        $visits = $this->countBy(TrafficEvent::VISIT, $from);
        $downloads = $this->countBy(TrafficEvent::DOWNLOAD, $from);
        $installs = $this->installsBySource($from);

        $sources = [];
        foreach (array_unique([...array_keys($visits), ...array_keys($downloads), ...array_keys($installs)]) as $source) {
            $sources[] = [
                'source' => $source,
                'label' => TrafficSource::label($source),
                'visits' => $visits[$source] ?? 0,
                'downloads' => $downloads[$source] ?? 0,
                'installs' => $installs[$source] ?? 0,
            ];
        }
        usort($sources, static fn ($a, $b) => [$b['downloads'] + $b['installs'], $b['visits']] <=> [$a['downloads'] + $a['installs'], $a['visits']]);

        return [
            'days' => $days,
            'trackingSince' => $this->db->fetchOne('SELECT MIN(created_at) FROM traffic_event') ?: null,
            'totals' => [
                'visits' => array_sum($visits),
                'downloads' => array_sum($downloads),
                'installs' => array_sum($installs),
                'installsUnknown' => $installs[TrafficSource::UNKNOWN] ?? 0,
            ],
            'sources' => $sources,
            'referrers' => array_map(static fn (array $row) => $row + ['label' => TrafficSource::label(TrafficSource::classify('https://' . $row['host'] . '/'))], $this->db->fetchAllAssociative(
                'SELECT referrer_host AS host, COUNT(*) AS visits FROM traffic_event WHERE type = ? AND created_at >= ? AND referrer_host IS NOT NULL GROUP BY referrer_host ORDER BY visits DESC LIMIT 25',
                [TrafficEvent::VISIT, $from],
            )),
            'landings' => $this->db->fetchAllAssociative(
                "SELECT landing_path AS path, SUM(type = 'visit') AS visits, SUM(type = 'download') AS downloads FROM traffic_event WHERE created_at >= ? GROUP BY landing_path ORDER BY visits DESC LIMIT 20",
                [$from],
            ),
            'campaigns' => $this->db->fetchAllAssociative(
                "SELECT utm_source || COALESCE(' / ' || utm_campaign, '') AS campaign, SUM(type = 'visit') AS visits, SUM(type = 'download') AS downloads FROM traffic_event WHERE created_at >= ? AND utm_source IS NOT NULL GROUP BY utm_source, utm_campaign ORDER BY visits DESC LIMIT 20",
                [$from],
            ),
            'daily' => $this->daily($from, $days),
            'recentDownloads' => array_map(static fn (array $row) => $row + ['label' => TrafficSource::label($row['source'])], $this->db->fetchAllAssociative(
                'SELECT created_at, source, referrer, landing_path, utm_source, utm_campaign FROM traffic_event WHERE type = ? AND created_at >= ? ORDER BY created_at DESC LIMIT 40',
                [TrafficEvent::DOWNLOAD, $from],
            )),
        ];
    }

    /** @return array<string, int> */
    private function countBy(string $type, string $from): array
    {
        return array_map('intval', $this->db->fetchAllKeyValue(
            'SELECT source, COUNT(*) FROM traffic_event WHERE type = ? AND created_at >= ? GROUP BY source',
            [$type, $from],
        ));
    }

    /**
     * Новые установки за период по источнику последнего скачивания с того же IP за 48 часов
     * до первого запуска. Нет такого скачивания — «Неизвестно» (скачали до начала учёта, через VPN и т. п.).
     *
     * @return array<string, int>
     */
    private function installsBySource(string $from): array
    {
        return array_map('intval', $this->db->fetchAllKeyValue(
            "SELECT COALESCE((
                SELECT t.source FROM traffic_event t
                WHERE t.type = 'download' AND t.ip = i.last_ip
                  AND t.created_at <= i.first_seen_at AND t.created_at >= datetime(i.first_seen_at, '-" . self::INSTALL_MATCH_HOURS . " hours')
                ORDER BY t.created_at DESC LIMIT 1
            ), ?) AS source, COUNT(*) FROM launcher_install i WHERE i.first_seen_at >= ? GROUP BY source",
            [TrafficSource::UNKNOWN, $from],
        ));
    }

    /** @return list<array{day: string, visits: int, downloads: int, installs: int}> */
    private function daily(string $from, int $days): array
    {
        $events = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT DATE(created_at) AS day, SUM(type = 'visit') AS visits, SUM(type = 'download') AS downloads FROM traffic_event WHERE created_at >= ? GROUP BY DATE(created_at)",
            [$from],
        ) as $row) {
            $events[$row['day']] = $row;
        }
        $installs = $this->db->fetchAllKeyValue('SELECT DATE(first_seen_at), COUNT(*) FROM launcher_install WHERE first_seen_at >= ? GROUP BY DATE(first_seen_at)', [$from]);

        $points = [];
        $day = new \DateTimeImmutable($from);
        for ($i = 0; $i < $days; $i++, $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $points[] = [
                'day' => $key,
                'visits' => (int) ($events[$key]['visits'] ?? 0),
                'downloads' => (int) ($events[$key]['downloads'] ?? 0),
                'installs' => (int) ($installs[$key] ?? 0),
            ];
        }

        return $points;
    }
}
