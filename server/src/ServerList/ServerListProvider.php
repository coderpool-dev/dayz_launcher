<?php

namespace App\ServerList;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Следит, чтобы снимок списка серверов был свежим, и обновляет его из источников.
 *
 * Обычно список обновляет cron (app:servers:refresh) раз в минуту. Если cron не работает,
 * обновление запускает первый запрос после истечения FRESH_TTL; остальные в это время
 * получают предыдущий снимок. Если источники недоступны — остаётся последний удачный снимок.
 */
final class ServerListProvider
{
    public const FRESH_TTL = 60;
    public const STALE_TTL = 6 * 60 * 60;
    private const REFRESH_TIME_BUDGET = 25;
    private const BATTLEMETRICS_MAX_PAGES = 100;

    public function __construct(
        private readonly UpstreamClient $upstream,
        private readonly ServerNormalizer $normalizer,
        private readonly ZeroPlayerTracker $zeroPlayers,
        private readonly ServerListStore $store,
        private readonly ServerListPublisher $publisher,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Обновляет снимок, если он устарел. Возвращает статус кэша:
     * hit, hit-after-lock, refreshed, stale-lock или stale-error.
     *
     * @throws ServerListUnavailableException если снимка нет и получить его не удалось
     */
    public function ensureFresh(bool $force = false): string
    {
        $age = $this->store->snapshotAge();
        if (!$force && $age !== null && $age < self::FRESH_TTL) {
            return 'hit';
        }

        $lock = $this->lockFactory->createLock('server-list-refresh', self::REFRESH_TIME_BUDGET + 30);
        if (!$lock->acquire()) {
            if ($age !== null && $age < self::STALE_TTL) {
                return 'stale-lock';
            }

            throw new ServerListUnavailableException('Cache is being refreshed and no stale cache is available', 503);
        }

        try {
            // Пока ждали блокировку, список мог обновить другой процесс.
            $age = $this->store->snapshotAge();
            if (!$force && $age !== null && $age < self::FRESH_TTL) {
                return 'hit-after-lock';
            }

            [$snapshot, $errors] = $this->refresh();
            if ($snapshot !== null) {
                return 'refreshed';
            }

            if ($age !== null) {
                // Источники недоступны: оставляем старый снимок и пробуем снова не раньше чем через FRESH_TTL.
                $this->store->touchSnapshot();

                return 'stale-error';
            }

            throw new ServerListUnavailableException('No servers fetched from upstream sources', 502, $errors);
        } finally {
            $lock->release();
        }
    }

    /**
     * Загружает серверы из всех источников, сохраняет снимок и публикует готовый ответ.
     *
     * @return array{0: ?ServerListSnapshot, 1: list<array>} снимок (null, если ничего не получено) и ошибки
     */
    public function refresh(): array
    {
        // Сырые ответы источников — десятки мегабайт JSON.
        ini_set('memory_limit', '512M');

        $startedAt = microtime(true);
        $deadline = new Deadline(self::REFRESH_TIME_BUDGET);
        $errors = [];
        $servers = [];
        $seen = [];

        $dzsaFetched = 0;
        foreach ($this->upstream->fetchDzsa($deadline, $errors) as $raw) {
            $server = $this->normalizer->normalizeDzsa($raw);
            if ($server !== null && !self::exists($seen, $server)) {
                self::add($servers, $seen, $server);
                ++$dzsaFetched;
            }
        }

        $battlemetrics = ['items' => [], 'pagesFetched' => 0, 'hasMore' => false, 'truncated' => false];
        if ($this->upstream->isBattlemetricsEnabled()) {
            $battlemetrics = $this->upstream->fetchBattlemetrics(self::BATTLEMETRICS_MAX_PAGES, $deadline, $errors);
            foreach ($battlemetrics['items'] as $raw) {
                $server = $this->normalizer->normalizeBattlemetrics($raw);
                if ($server !== null && !self::exists($seen, $server)) {
                    self::add($servers, $seen, $server);
                }
            }
        }

        if ($servers === []) {
            $this->logger->warning('Server list refresh returned no servers', ['errors' => $errors]);

            return [null, $errors];
        }

        $now = time();
        $this->store->writeZeroPlayerState($this->zeroPlayers->update($servers, $this->store->readZeroPlayerState(), $now));

        $meta = [
            'truncated' => $battlemetrics['truncated'] || $deadline->isExceeded(),
            'timeBudgetSeconds' => $deadline->totalSeconds(),
            'battlemetricsEnabled' => $this->upstream->isBattlemetricsEnabled(),
            'battlemetricsMaxPages' => self::BATTLEMETRICS_MAX_PAGES,
            'battlemetricsPagesFetched' => $battlemetrics['pagesFetched'],
            'battlemetricsHasMore' => $battlemetrics['hasMore'],
            'dzsaFetched' => $dzsaFetched,
            'durationSeconds' => round(microtime(true) - $startedAt, 3),
        ];

        $this->store->writeSnapshot($servers, $errors, $meta, $now);
        $snapshot = new ServerListSnapshot($servers, $errors, $meta, $now, 'refreshed');
        $this->publisher->publish($snapshot);

        return [$snapshot, $errors];
    }

    private static function add(array &$servers, array &$seen, array $server): void
    {
        $servers[] = $server;
        foreach (ServerNormalizer::endpointKeys($server) as $key) {
            $seen[$key] = true;
        }
    }

    private static function exists(array $seen, array $server): bool
    {
        foreach (ServerNormalizer::endpointKeys($server) as $key) {
            if (isset($seen[$key])) {
                return true;
            }
        }

        return false;
    }
}
