<?php

namespace App\ServerList;

/**
 * Собранный список серверов вместе с диагностикой и статусом кэша.
 */
final class ServerListSnapshot
{
    /**
     * @param list<array> $servers нормализованные серверы (все, без фильтрации пустых)
     * @param list<array> $errors
     */
    public function __construct(
        public readonly array $servers,
        public readonly array $errors,
        public readonly array $meta,
        public readonly int $storedAt,
        public readonly string $cacheStatus,
    ) {
    }

    public static function fromStored(array $stored, string $cacheStatus, array $extraErrors = []): self
    {
        return new self($stored['servers'], array_merge($stored['errors'], $extraErrors), $stored['meta'], (int) $stored['storedAt'], $cacheStatus);
    }

    public function isFromCache(): bool
    {
        return $this->cacheStatus !== 'refreshed';
    }
}
