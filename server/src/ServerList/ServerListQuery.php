<?php

namespace App\ServerList;

/**
 * Поиск, сортировка и лимит для выдачи /api/servers.
 */
final class ServerListQuery
{
    /**
     * @param list<array> $servers
     * @return list<array>
     */
    public function apply(array $servers, string $search = '', string $nameSearch = '', int $limit = 0): array
    {
        $search = mb_strtolower(trim($search));
        $nameSearch = mb_strtolower(trim($nameSearch));

        if ($nameSearch !== '') {
            $servers = array_filter($servers, static fn (array $s): bool => str_contains(mb_strtolower((string) ($s['name'] ?? '')), $nameSearch));
        } elseif ($search !== '') {
            $servers = array_filter($servers, static fn (array $s): bool => self::matches($s, $search));
        }

        $servers = array_values($servers);
        usort($servers, self::compare(...));

        return $limit > 0 ? array_slice($servers, 0, $limit) : $servers;
    }

    /** @param list<array> $servers */
    public function stats(array $servers): array
    {
        $online = 0;
        $modded = 0;
        $players = 0;
        foreach ($servers as $server) {
            $online += empty($server['online']) ? 0 : 1;
            $modded += empty($server['modIds']) ? 0 : 1;
            $players += (int) ($server['players'] ?? 0);
        }

        return [
            'totalServers' => count($servers),
            'onlineServers' => $online,
            'moddedServers' => $modded,
            'totalPlayers' => $players,
            'avgPlayers' => $online > 0 ? (int) round($players / $online) : 0,
        ];
    }

    /** Спонсоры (по приоритету) → по онлайну → по рангу BattleMetrics → по имени. */
    public static function compare(array $a, array $b): int
    {
        return [(int) !empty($b['sponsor']), (int) ($b['sponsorPriority'] ?? 0), (int) ($b['players'] ?? 0), (int) ($a['rank'] ?? 999999999)]
            <=> [(int) !empty($a['sponsor']), (int) ($a['sponsorPriority'] ?? 0), (int) ($a['players'] ?? 0), (int) ($b['rank'] ?? 999999999)]
            ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    }

    private static function matches(array $server, string $search): bool
    {
        foreach (['name', 'ip', 'mapName', 'map', 'country'] as $field) {
            if (str_contains(mb_strtolower((string) ($server[$field] ?? '')), $search)) {
                return true;
            }
        }

        foreach (['mods', 'modIds'] as $field) {
            foreach (is_array($server[$field] ?? null) ? $server[$field] : [] as $value) {
                if (str_contains(mb_strtolower((string) $value), $search)) {
                    return true;
                }
            }
        }

        return false;
    }
}
