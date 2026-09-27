<?php

namespace App\ServerList;

/**
 * Запоминает, с какого момента на сервере 0 игроков. Серверы, пустые дольше HIDE_AFTER_SECONDS,
 * не показываются: иначе список на 20+ тысяч серверов состоит в основном из пустых.
 */
final class ZeroPlayerTracker
{
    public const HIDE_AFTER_SECONDS = 30 * 60;
    private const PRUNE_AFTER_SECONDS = 7 * 24 * 60 * 60;

    /**
     * Обновляет состояние по свежему списку серверов.
     *
     * @param list<array> $servers
     * @param array<string, array{zeroSince: int, lastSeen: int}> $state
     * @return array<string, array{zeroSince: int, lastSeen: int}>
     */
    public function update(array $servers, array $state, int $now): array
    {
        $seen = [];
        foreach ($servers as $server) {
            $key = self::key($server);
            if ($key === '') {
                continue;
            }

            $seen[$key] = true;
            if ((int) ($server['players'] ?? 0) > 0) {
                unset($state[$key]);
                continue;
            }

            $zeroSince = (int) ($state[$key]['zeroSince'] ?? 0);
            $state[$key] = ['zeroSince' => $zeroSince > 0 ? $zeroSince : $now, 'lastSeen' => $now];
        }

        foreach ($state as $key => $entry) {
            $lastSeen = (int) ($entry['lastSeen'] ?? 0);
            if (!isset($seen[$key]) && ($lastSeen <= 0 || $now - $lastSeen > self::PRUNE_AFTER_SECONDS)) {
                unset($state[$key]);
            }
        }

        return $state;
    }

    /**
     * @param list<array> $servers
     * @return list<array>
     */
    public function filterVisible(array $servers, array $state, int $now): array
    {
        return array_values(array_filter($servers, static function (array $server) use ($state, $now): bool {
            if ((int) ($server['players'] ?? 0) > 0) {
                return true;
            }

            $zeroSince = $state[self::key($server)]['zeroSince'] ?? null;

            return $zeroSince === null || $now - (int) $zeroSince < self::HIDE_AFTER_SECONDS;
        }));
    }

    private static function key(array $server): string
    {
        $keys = ServerNormalizer::endpointKeys($server);
        if ($keys !== []) {
            return $keys[0];
        }

        $id = trim((string) ($server['id'] ?? ''));

        return $id !== '' ? "id:{$id}" : '';
    }
}
