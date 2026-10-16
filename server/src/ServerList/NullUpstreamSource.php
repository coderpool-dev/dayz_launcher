<?php

namespace App\ServerList;

/**
 * Заглушка на случай, когда приватного UpstreamClient нет (CI, чужая копия репозитория):
 * серверов не отдаёт, а причину пишет в ошибки обновления.
 */
final class NullUpstreamSource implements UpstreamSource
{
    private const ERROR = 'Private UpstreamClient is not installed';

    public function isBattlemetricsEnabled(): bool
    {
        return false;
    }

    public function fetchDzsa(Deadline $deadline, array &$errors): array
    {
        $errors[] = ['source' => 'proxy', 'error' => self::ERROR];

        return [];
    }

    public function fetchBattlemetrics(int $maxPages, Deadline $deadline, array &$errors): array
    {
        return ['items' => [], 'pagesFetched' => 0, 'hasMore' => false, 'truncated' => false];
    }
}
