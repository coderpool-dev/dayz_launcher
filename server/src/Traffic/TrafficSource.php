<?php

namespace App\Traffic;

/** Откуда пришёл посетитель: по utm/ref-метке и адресу ссылающегося сайта. */
final class TrafficSource
{
    public const DIRECT = 'direct';
    public const INTERNAL = 'internal';
    public const OTHER = 'other';
    public const UNKNOWN = 'unknown';

    public const LABELS = [
        'google' => 'Google',
        'yandex' => 'Яндекс',
        'bing' => 'Bing',
        'duckduckgo' => 'DuckDuckGo',
        'steam' => 'Steam',
        'vk' => 'ВКонтакте',
        'telegram' => 'Telegram',
        'discord' => 'Discord',
        'youtube' => 'YouTube',
        'twitch' => 'Twitch',
        'reddit' => 'Reddit',
        'pikabu' => 'Пикабу',
        'dtf' => 'DTF',
        'habr' => 'Хабр',
        'splatoon' => 'S-Platoon',
        'dayzrussia' => 'DayZ Russia',
        'sonetcord' => 'SonetCord',
        self::DIRECT => 'Без источника (прямой / скрытый)',
        self::INTERNAL => 'Внутри сайта',
        self::OTHER => 'Другие сайты',
        self::UNKNOWN => 'Неизвестно',
    ];

    /** «google.» — любой домен второго уровня (google.com, www.google.ru); остальное — домен и его поддомены. */
    private const HOSTS = [
        'youtube.' => 'youtube',
        'youtu.be' => 'youtube',
        'google.' => 'google',
        'yandex.' => 'yandex',
        'ya.ru' => 'yandex',
        'dzen.ru' => 'yandex',
        'bing.com' => 'bing',
        'duckduckgo.' => 'duckduckgo',
        'steamcommunity.com' => 'steam',
        'steampowered.com' => 'steam',
        'vk.com' => 'vk',
        'vk.ru' => 'vk',
        'vkvideo.ru' => 'vk',
        't.me' => 'telegram',
        'telegram.' => 'telegram',
        'discord.' => 'discord',
        'twitch.tv' => 'twitch',
        'reddit.com' => 'reddit',
        'pikabu.ru' => 'pikabu',
        'dtf.ru' => 'dtf',
        'habr.com' => 'habr',
        's-platoon.ru' => 'splatoon',
        'dayzrussia.com' => 'dayzrussia',
        'sonetcord.ru' => 'sonetcord',
    ];

    /** Свои адреса: переход с них — не новый визит. dayz.sonetcord.ru и dayz.goidacord.ru — старые адреса сайта. */
    private const OWN_HOSTS = ['prepiskadayz.ru', 'prepiskadayz.online', 'dayz.sonetcord.ru', 'dayz.goidacord.ru', 'localhost', '127.0.0.1'];

    public static function classify(?string $referrer, ?string $utmSource = null): string
    {
        $utm = strtolower(trim((string) $utmSource));
        if ($utm !== '') {
            return self::matchHost($utm) ?? (isset(self::LABELS[$utm]) ? $utm : self::OTHER);
        }

        $host = self::host($referrer);
        if ($host === null) {
            return self::DIRECT;
        }

        foreach (self::OWN_HOSTS as $own) {
            if ($host === $own || str_ends_with($host, '.' . $own)) {
                return self::INTERNAL;
            }
        }

        return self::matchHost($host) ?? self::OTHER;
    }

    public static function label(string $source): string
    {
        return self::LABELS[$source] ?? $source;
    }

    public static function host(?string $referrer): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? strtolower(substr($host, 0, 255)) : null;
    }

    private static function matchHost(string $host): ?string
    {
        foreach (self::HOSTS as $needle => $source) {
            $matches = str_ends_with($needle, '.')
                ? str_starts_with($host, $needle) || str_contains($host, '.' . $needle)
                : $host === $needle || str_ends_with($host, '.' . $needle);
            if ($matches) {
                return $source;
            }
        }

        return null;
    }
}
