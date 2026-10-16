<?php

namespace App\ServerList;

/**
 * Откуда берётся сырой список серверов (DZSA, BattleMetrics).
 *
 * Настоящая реализация — UpstreamClient — приватная часть проекта: её нет в репозитории
 * (см. server/.gitignore), она лежит только на сервере и у разработчика.
 * Без неё работает NullUpstreamSource, и список серверов просто не обновляется.
 */
interface UpstreamSource
{
    public function isBattlemetricsEnabled(): bool;

    /**
     * @param list<array> $errors
     * @return list<array> сырые серверы DZSA
     */
    public function fetchDzsa(Deadline $deadline, array &$errors): array;

    /**
     * @param list<array> $errors
     * @return array{items: list<array>, pagesFetched: int, hasMore: bool, truncated: bool}
     */
    public function fetchBattlemetrics(int $maxPages, Deadline $deadline, array &$errors): array;
}
