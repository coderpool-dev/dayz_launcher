<?php

namespace App\Stats;

/**
 * Событие от лаунчера: POST /api/launcher/events.
 */
final class LauncherEvent
{
    /** Лаунчер запущен. */
    public const START = 'start';
    /** Лаунчер открыт (раз в несколько минут) — для онлайна. */
    public const HEARTBEAT = 'heartbeat';
    /** Нажата кнопка «Играть». */
    public const PLAY = 'play';

    public const TYPES = [self::START, self::HEARTBEAT, self::PLAY];

    public function __construct(
        public readonly string $type,
        public readonly string $launcherId,
        public readonly string $ip,
        public readonly string $version,
        public readonly string $serverId = '',
        public readonly string $serverName = '',
        public readonly string $serverAddress = '',
    ) {
    }

    /**
     * Разбирает и проверяет тело запроса. Возвращает null для некорректных данных.
     */
    public static function fromRequest(array $payload, string $launcherId, string $ip): ?self
    {
        $type = (string) ($payload['event'] ?? '');
        if (!in_array($type, self::TYPES, true) || !self::isLauncherId($launcherId)) {
            return null;
        }

        $version = (string) ($payload['version'] ?? '');
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $version = '0.0.0';
        }

        return new self(
            $type,
            strtolower($launcherId),
            $ip,
            $version,
            mb_substr(preg_replace('/\D/', '', (string) ($payload['serverId'] ?? '')) ?? '', 0, 20),
            mb_substr(trim((string) ($payload['serverName'] ?? '')), 0, 255),
            mb_substr(trim((string) ($payload['serverAddress'] ?? '')), 0, 64),
        );
    }

    private static function isLauncherId(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }
}
