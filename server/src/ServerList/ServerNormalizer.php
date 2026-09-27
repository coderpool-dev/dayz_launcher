<?php

namespace App\ServerList;

/**
 * Приводит сервер из DZSA или BattleMetrics к единому формату ответа /api/servers.
 * Формат — контракт с лаунчером (ServerJsonParser в C#), ключи не переименовывать.
 */
final class ServerNormalizer
{
    /**
     * Фейковые «зеркала» популярных серверов: известные подсети, высокие порты
     * и завышенное число слотов. Такие серверы в выдачу не попадают.
     */
    private const MIRROR_SUBNETS = ['31.77.142.', '31.76.54.'];
    private const MIRROR_MIN_PORT = 30000;
    private const MIRROR_MIN_MAX_PLAYERS = 120;

    /** Если название начинается с префикса, в displayName отдаётся только префикс. */
    private const DISPLAY_NAME_PREFIXES = ['Russian Classic Deathmatch'];

    public function normalizeDzsa(array $raw): ?array
    {
        $endpoint = is_array($raw['endpoint'] ?? null) ? $raw['endpoint'] : [];

        $ip = trim((string) ($endpoint['ip'] ?? $raw['ip'] ?? ''));
        $queryPort = (int) ($endpoint['port'] ?? $raw['queryPort'] ?? $raw['port'] ?? 0);
        $gamePort = (int) ($raw['gamePort'] ?? $raw['game_port'] ?? $queryPort);
        $maxPlayers = (int) ($raw['maxPlayers'] ?? $raw['max_players'] ?? 0);

        if ($ip === '' || $queryPort <= 0 || $gamePort <= 0 || $this->isMirror($ip, $queryPort, $gamePort, $maxPlayers)) {
            return null;
        }

        [$modIds, $mods] = $this->normalizeDzsaMods($raw['mods'] ?? []);
        $name = (string) ($raw['name'] ?? 'Unknown');
        $map = (string) ($raw['map'] ?? $raw['mission'] ?? self::detectMap($name));
        $serverTime = (string) ($raw['time'] ?? '');

        return [
            'id' => self::stableId($ip, $queryPort),
            'source' => 'dzsa',
            'name' => $name,
            'displayName' => $this->displayName($name),
            'ip' => $ip,
            'port' => $gamePort,
            'gamePort' => $gamePort,
            'queryPort' => $queryPort,
            'map' => self::mapKey($map),
            'mapName' => self::mapName($map),
            'players' => (int) ($raw['players'] ?? 0),
            'maxPlayers' => $maxPlayers,
            'ping' => 0,
            'version' => (string) ($raw['version'] ?? ''),
            'online' => true,
            'password' => (bool) ($raw['password'] ?? false),
            'vac' => (bool) ($raw['vac'] ?? true),
            'perspective' => !empty($raw['firstPersonOnly']) ? '1pp' : '3pp',
            'time' => self::isClock($serverTime) ? $serverTime : '-',
            'serverTime' => self::isClock($serverTime) ? $serverTime : '',
            'mode' => count($modIds) > 0 ? 'modded' : 'community',
            'mods' => $mods,
            'modIds' => $modIds,
            'modsSource' => count($modIds) > 0 ? 'dzsa' : '',
            'hive' => 'Private',
            'battleye' => (bool) ($raw['battlEye'] ?? $raw['battleye'] ?? true),
            // Спонсорство DZSA (sponsor/profile/staticName) игнорируется — спонсоров задаёт админка.
            'sponsor' => false,
            'sponsorPriority' => 0,
            'profile' => false,
            'staticName' => false,
        ];
    }

    public function normalizeBattlemetrics(array $raw): ?array
    {
        $attrs = is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];
        $details = is_array($attrs['details'] ?? null) ? $attrs['details'] : [];

        $ip = trim((string) ($attrs['ip'] ?? ''));
        $queryPort = (int) ($details['queryPort'] ?? $details['query_port'] ?? $attrs['portQuery'] ?? $attrs['queryPort'] ?? $attrs['port'] ?? 0);
        $gamePort = (int) ($details['gamePort'] ?? $details['game_port'] ?? $details['port'] ?? $attrs['gamePort'] ?? $attrs['port'] ?? $queryPort);
        $maxPlayers = (int) ($attrs['maxPlayers'] ?? 60);

        if ($ip === '' || $queryPort <= 0 || $gamePort <= 0 || $this->isMirror($ip, $queryPort, $gamePort, $maxPlayers)) {
            return null;
        }

        $modIds = self::stringList($details['modIds'] ?? []);
        $mods = self::stringList($details['modNames'] ?? []);
        $name = (string) ($attrs['name'] ?? 'Unknown');
        $map = (string) ($details['map'] ?? self::detectMap($name));
        $time = (string) ($details['time'] ?? '-');

        return [
            'id' => (int) ($raw['id'] ?? self::stableId($ip, $queryPort)),
            'source' => 'battlemetrics',
            'name' => $name,
            'displayName' => $this->displayName($name),
            'ip' => $ip,
            'port' => $gamePort,
            'gamePort' => $gamePort,
            'queryPort' => $queryPort,
            'map' => self::mapKey($map),
            'mapName' => self::mapName($map),
            'players' => (int) ($attrs['players'] ?? 0),
            'maxPlayers' => $maxPlayers,
            'ping' => 0,
            'version' => (string) ($details['version'] ?? ''),
            'online' => strtolower((string) ($attrs['status'] ?? 'online')) === 'online',
            'password' => (bool) ($details['password'] ?? false),
            'vac' => true,
            'perspective' => !empty($details['third_person']) ? '3pp' : '1pp',
            'time' => $time,
            'serverTime' => $time !== '-' ? $time : '',
            'mode' => count($modIds) > 0 ? 'modded' : 'community',
            'mods' => $mods,
            'modIds' => $modIds,
            'modsSource' => count($modIds) > 0 ? 'battlemetrics' : '',
            'hive' => 'Private',
            'battleye' => true,
            'rank' => (int) ($attrs['rank'] ?? 0),
            'country' => (string) ($attrs['country'] ?? ''),
            'sponsor' => false,
            'sponsorPriority' => 0,
            'profile' => false,
            'staticName' => false,
        ];
    }

    /**
     * Стабильный ID сервера. Совпадает с ServerJsonParser.StableServerId в лаунчере.
     */
    public static function stableId(string $ip, int $queryPort): int
    {
        return 1000000000 + (int) sprintf('%u', crc32("dzsa:{$ip}:{$queryPort}")) % 1000000000;
    }

    /** Ключи ip:port по всем портам сервера — для поиска дублей между источниками. */
    public static function endpointKeys(array $server): array
    {
        $ip = trim((string) ($server['ip'] ?? ''));
        if ($ip === '') {
            return [];
        }

        $ports = array_unique(array_filter([
            (int) ($server['queryPort'] ?? 0),
            (int) ($server['gamePort'] ?? 0),
            (int) ($server['port'] ?? 0),
        ], static fn (int $port): bool => $port > 0));

        return array_map(static fn (int $port): string => "{$ip}:{$port}", array_values($ports));
    }

    public static function mapKey(string $map): string
    {
        return match ($lower = mb_strtolower(trim($map))) {
            'chernarus', '' => 'chernarusplus',
            'livonia' => 'enoch',
            'deer isle' => 'deerisle',
            'takistan' => 'takistanplus',
            default => $lower,
        };
    }

    public static function mapName(string $map): string
    {
        return match (self::mapKey($map)) {
            'chernarusplus' => 'Chernarus',
            'enoch' => 'Livonia',
            'deerisle' => 'Deer Isle',
            'namalsk' => 'Namalsk',
            'takistanplus' => 'Takistan',
            'sakhal' => 'Sakhal',
            default => $map !== '' ? ucfirst($map) : 'Chernarus',
        };
    }

    public static function detectMap(string $name): string
    {
        $lower = mb_strtolower($name);

        return match (true) {
            str_contains($lower, 'livonia') || str_contains($lower, 'enoch') => 'enoch',
            str_contains($lower, 'namalsk') => 'namalsk',
            str_contains($lower, 'deer isle') || str_contains($lower, 'deerisle') => 'deerisle',
            str_contains($lower, 'takistan') => 'takistanplus',
            str_contains($lower, 'sakhal') => 'sakhal',
            default => 'chernarusplus',
        };
    }

    private function isMirror(string $ip, int $queryPort, int $gamePort, int $maxPlayers): bool
    {
        foreach (self::MIRROR_SUBNETS as $subnet) {
            if (str_starts_with($ip, $subnet)) {
                return $gamePort >= self::MIRROR_MIN_PORT
                    && $queryPort >= self::MIRROR_MIN_PORT
                    && $maxPlayers >= self::MIRROR_MIN_MAX_PLAYERS;
            }
        }

        return false;
    }

    private function displayName(string $name): string
    {
        foreach (self::DISPLAY_NAME_PREFIXES as $prefix) {
            if (stripos($name, $prefix) === 0) {
                return $prefix;
            }
        }

        return $name;
    }

    /**
     * Моды DZSA: массив объектов { steamWorkshopId, name }. Дедупликация по ID,
     * чтобы имена оставались на тех же позициях, что и ID.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function normalizeDzsaMods(mixed $modsRaw): array
    {
        $ids = [];
        $names = [];

        foreach (is_array($modsRaw) ? $modsRaw : [] as $mod) {
            if (!is_array($mod)) {
                continue;
            }

            $id = (string) ($mod['steamWorkshopId'] ?? $mod['steam_workshop_id'] ?? $mod['workshopId'] ?? $mod['id'] ?? '');
            if ($id === '' || !ctype_digit($id) || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
            $names[] = trim((string) ($mod['name'] ?? '')) ?: "Workshop {$id}";
        }

        return [$ids, $names];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return array_values(array_unique($result));
    }

    private static function isClock(string $value): bool
    {
        return preg_match('/^\d{2}:\d{2}$/', $value) === 1;
    }
}
