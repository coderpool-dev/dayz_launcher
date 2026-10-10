<?php

namespace App\ServerList;

use Symfony\Component\Lock\LockFactory;

/**
 * Декоратор: добавляет файловый кэш, защиту от параллельных обновлений и fallback.
 * Свежесть проверяется по mtime, без декодирования большого JSON на каждом запросе.
 */
final class CachedServerListProvider implements ServerListProviderInterface
{
    // Интервал cron (2 мин) плюс запас на обновление.
    public const FRESH_TTL = 150;
    public const STALE_TTL = 6 * 60 * 60;
    private const LOCK_TTL = 55;

    public function __construct(
        private readonly ServerListProviderInterface $inner,
        private readonly ServerListStore $store,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function ensureFresh(bool $force = false): string
    {
        $age = $this->store->snapshotAge();
        if (!$force && $age !== null && $age < self::FRESH_TTL) {
            return 'hit';
        }

        $lock = $this->lockFactory->createLock('server-list-refresh', self::LOCK_TTL);
        if (!$lock->acquire()) {
            if ($age !== null && $age < self::STALE_TTL) {
                return 'stale-lock';
            }

            throw new ServerListUnavailableException('Cache is being refreshed and no stale cache is available', 503);
        }

        try {
            // Снимок мог обновиться между первой проверкой и получением блокировки.
            $age = $this->store->snapshotAge();
            if (!$force && $age !== null && $age < self::FRESH_TTL) {
                return 'hit-after-lock';
            }

            try {
                return $this->inner->ensureFresh(force: true);
            } catch (ServerListUnavailableException $e) {
                if ($age === null) {
                    throw $e;
                }

                // Сохраняем последний удачный снимок; откладываем повторную попытку.
                $this->store->touchSnapshot();

                return 'stale-error';
            }
        } finally {
            $lock->release();
        }
    }
}
