<?php

namespace App\ServerList;

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

    public function __construct(
        private readonly ServerListStore $store,
        private readonly ServerListQuery $query,
        private readonly SponsorDirectory $sponsors,
        private readonly ZeroPlayerTracker $zeroPlayers,
    ) {
    }

    /** Пересобирает готовый ответ для лаунчеров. */
    public function publish(?ServerListSnapshot $snapshot = null): void
    {
        $snapshot ??= $this->loadSnapshot('hit');
        if ($snapshot === null) {
            return;
        }

        $this->store->writePublicPayload(json_encode($this->buildPayload($snapshot), self::JSON_FLAGS));
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
