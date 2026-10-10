<?php

namespace App\ServerList;

use Psr\Log\LoggerInterface;

/**
 * Собирает ответ /api/servers: скрывает давно пустые серверы, помечает спонсоров,
 * применяет поиск, сортировку и лимит.
 *
 * Ответ без параметров (его запрашивают все лаунчеры) собирается заранее — после каждого
 * обновления списка и при изменении спонсоров — и отдаётся готовым файлом.
 */
final class ServerListPublisher
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    /** Сколько серверов в таблице «Больше всего игроков сейчас» на лендинге. */
    private const LANDING_TOP = 5;

    public function __construct(
        private readonly ServerListStore $store,
        private readonly ServerListQuery $query,
        private readonly SponsorDirectory $sponsors,
        private readonly ZeroPlayerTracker $zeroPlayers,
        private readonly ServerPopulationHistory $history,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Пересобирает готовый ответ для лаунчеров. */
    public function publish(?ServerListSnapshot $snapshot = null): void
    {
        $snapshot ??= $this->loadSnapshot('hit');
        if ($snapshot === null) {
            return;
        }

        if (!$snapshot->isFromCache()) {
            try {
                $this->history->record($snapshot->servers, $snapshot->storedAt);
            } catch (\Throwable $e) {
                $this->logger->error('Population history could not be recorded', ['exception' => $e]);
            }
        }

        $payload = $this->buildPayload($snapshot);
        $this->store->writePublicPayload(json_encode($payload, self::JSON_FLAGS));
        $stats = $payload['stats'];
        $this->store->writeStats($stats['totalServers'], $stats['moddedServers'], $stats['totalPlayers'], $payload['timestamp'], self::mostPopulatedModded($payload['servers']));
    }

    /**
     * Самые населённые серверы с модами для лендинга. Спонсоры стоят в списке выше по договорённости,
     * а не по онлайну, поэтому их здесь нет; серверов с паролем тоже. Список уже отсортирован по онлайну.
     *
     * @return list<array{name: string, fullName: string, map: string, players: int, maxPlayers: int, mods: int}>
     */
    private static function mostPopulatedModded(array $servers): array
    {
        $top = [];
        foreach ($servers as $server) {
            if (!empty($server['sponsor']) || !empty($server['password']) || empty($server['modIds'])) {
                continue;
            }

            $fullName = trim((string) preg_replace('/\s+/u', ' ', (string) ($server['displayName'] ?? $server['name'] ?? '')));
            $top[] = [
                'name' => self::shortName($fullName),
                'fullName' => $fullName,
                'map' => (string) ($server['mapName'] ?? $server['map'] ?? ''),
                'players' => (int) ($server['players'] ?? 0),
                'maxPlayers' => (int) ($server['maxPlayers'] ?? 0),
                'mods' => count($server['modIds']),
            ];
            if (count($top) === self::LANDING_TOP) {
                break;
            }
        }

        return $top;
    }

    /**
     * Короткое название для лендинга: без ссылок (discord.gg/…, адреса сайтов), без объявлений
     * в звёздочках («**WIPED 9/25**») и без тегов после «|».
     * «OrigemZ |Solo-Duo-Trio|NOVA SEASON|discord.gg/origemz» → «OrigemZ».
     */
    private static function shortName(string $name): string
    {
        $withoutLinks = (string) preg_replace(
            [
                '~(?:https?://)?(?:www\.)?(?:discord\.(?:gg|com/invite)/\S*|[\w-]+\.(?:com|net|org|gg|ru|io|br|de|eu|us|uk|fr|pl|cz|xyz|online|pro|site|fun|club|store|shop|app)(?:/\S*)?)~iu',
                '~\*+[^*|]*\*+|\*+~u',
            ],
            '',
            $name,
        );
        foreach (explode('|', $withoutLinks) as $part) {
            $part = trim((string) preg_replace('/\s+/u', ' ', $part), " -–—:");
            if ($part !== '') {
                return $part;
            }
        }

        return $name;
    }

    /** Ответ с поиском/лимитом — собирается на лету из полного снимка. */
    public function buildPayload(ServerListSnapshot $snapshot, string $search = '', string $nameSearch = '', int $limit = 0): array
    {
        $now = time();
        $visible = $this->zeroPlayers->filterVisible($snapshot->servers, $this->store->readZeroPlayerState(), $now);
        $servers = $this->query->apply($this->sponsors->apply($visible), $search, $nameSearch, $limit);

        return [
            'success' => count($servers) > 0,
            'source' => 'goida',
            'cache' => $snapshot->isFromCache(),
            'timestamp' => $now,
            'count' => count($servers),
            'stats' => $this->query->stats($servers),
            'meta' => $snapshot->meta + [
                'cacheStatus' => $snapshot->cacheStatus,
                'storedAt' => $snapshot->storedAt,
                'search' => $search,
                'nameSearch' => $nameSearch,
                'limit' => $limit,
            ],
            'errors' => $snapshot->errors,
            'servers' => $servers,
        ];
    }

    public function loadSnapshot(string $cacheStatus): ?ServerListSnapshot
    {
        // Полный список — сотни мегабайт в виде PHP-массивов.
        ini_set('memory_limit', '512M');
        $stored = $this->store->readSnapshot();

        return $stored === null ? null : ServerListSnapshot::fromStored($stored, $cacheStatus);
    }
}
