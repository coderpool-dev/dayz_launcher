<?php

namespace App\ServerList;

use Psr\Log\LoggerInterface;

/**
 * Загружает список из источников, нормализует его, сохраняет и публикует снимок.
 * Кэширование и блокировки добавляет CachedServerListProvider через общий интерфейс.
 */
final class ServerListProvider implements ServerListProviderInterface
{
    private const REFRESH_TIME_BUDGET = 25;
    private const BATTLEMETRICS_MAX_PAGES = 100;

    public function __construct(
        private readonly UpstreamClient $upstream,
        private readonly ServerNormalizer $normalizer,
        private readonly ZeroPlayerTracker $zeroPlayers,
        private readonly ServerListStore $store,
        private readonly ServerListPublisher $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Всегда загружает новый снимок; параметр force нужен для общего контракта с декоратором.
     *
     * @throws ServerListUnavailableException если источники не вернули ни одного сервера
     */
    public function ensureFresh(bool $force = false): string
    {
        [$snapshot, $errors] = $this->refresh();
        if ($snapshot === null) {
            throw new ServerListUnavailableException('No servers fetched from upstream sources', 502, $errors);
        }

        return 'refreshed';
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
            if ($server !== null && self::addUnique($servers, $seen, $server)) {
                ++$dzsaFetched;
            }
        }

        $battlemetrics = ['items' => [], 'pagesFetched' => 0, 'hasMore' => false, 'truncated' => false];
        if ($this->upstream->isBattlemetricsEnabled()) {
            $battlemetrics = $this->upstream->fetchBattlemetrics(self::BATTLEMETRICS_MAX_PAGES, $deadline, $errors);
            foreach ($battlemetrics['items'] as $raw) {
                $server = $this->normalizer->normalizeBattlemetrics($raw);
                if ($server !== null) {
                    self::addUnique($servers, $seen, $server);
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

    /** Добавляет сервер, если ни один его адрес ip:port ещё не встречался. Ключи считаются один раз. */
    private static function addUnique(array &$servers, array &$seen, array $server): bool
    {
        $keys = ServerNormalizer::endpointKeys($server);
        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                return false;
            }
        }

        $servers[] = $server;
        foreach ($keys as $key) {
            $seen[$key] = true;
        }

        return true;
    }
}
