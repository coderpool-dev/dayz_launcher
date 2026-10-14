<?php

namespace App\Seo;

/**
 * Подборки мониторинга под поисковые запросы: «pve сервера dayz», «dayz сервера без модов» и т. п.
 * Режим определяется по тегу в названии сервера (ServerCategories) — так и пишем на странице.
 */
final class MonitoringPages
{
    /** Карта получает свою страницу, если на ней хотя бы столько серверов; меньше — страница без индексации. */
    public const MAP_MIN_SERVERS = 5;

    /** @return array<string, array{chip: string, h1: string, title: string, about: string}> */
    public static function modes(): array
    {
        return [
            'pve' => ['chip' => 'PvE', 'h1' => 'PvE серверы DayZ', 'title' => 'PvE серверы DayZ — мониторинг, онлайн и IP',
                'about' => 'На PvE-серверах игроки не воюют друг с другом: опасность — заражённые, голод и холод.'],
            'pvp' => ['chip' => 'PvP', 'h1' => 'PvP серверы DayZ', 'title' => 'PvP серверы DayZ — мониторинг, онлайн и IP',
                'about' => 'PvP-серверы рассчитаны на бои между игроками: рейды баз, перестрелки за лут и территорию.'],
            'rp' => ['chip' => 'RP', 'h1' => 'RP серверы DayZ', 'title' => 'RP серверы DayZ (ролевые) — мониторинг и онлайн',
                'about' => 'На ролевых серверах играют за персонажа: у каждого своя история, а правила запрещают убийства без повода.'],
            'deathmatch' => ['chip' => 'Deathmatch', 'h1' => 'Deathmatch серверы DayZ', 'title' => 'Deathmatch серверы DayZ — мониторинг и онлайн',
                'about' => 'Deathmatch — бои без выживания: появляетесь со снаряжением и сразу идёте в перестрелку.'],
            'survival' => ['chip' => 'Survival', 'h1' => 'Survival серверы DayZ', 'title' => 'Survival серверы DayZ — мониторинг и онлайн',
                'about' => 'Survival-серверы делают упор на выживание: еда, вода, болезни и погода важнее перестрелок.'],
            'vanilla' => ['chip' => 'Без модов', 'h1' => 'Серверы DayZ без модов', 'title' => 'Ванильные серверы DayZ без модов — мониторинг',
                'about' => 'Ванильные серверы работают без модов из Steam Workshop: подключиться можно сразу, ничего не скачивая.'],
            'modded' => ['chip' => 'С модами', 'h1' => 'Серверы DayZ с модами', 'title' => 'Серверы DayZ с модами — мониторинг, онлайн и IP',
                'about' => 'Для игры на этих серверах нужны моды из Steam Workshop. Лаунчер PREPISKA скачивает их сам перед подключением.'],
            '1pp' => ['chip' => 'Только 1PP', 'h1' => 'Серверы DayZ от первого лица (1PP)', 'title' => 'Серверы DayZ 1PP — только вид от первого лица',
                'about' => 'На 1PP-серверах вид от третьего лица отключён: нельзя заглядывать за углы из-за стены.'],
        ];
    }

    public static function matchesMode(string $mode, array $server): bool
    {
        return match ($mode) {
            'vanilla' => $server['modCount'] === 0,
            'modded' => $server['modCount'] > 0,
            '1pp' => $server['perspective'] === '1pp',
            default => in_array($mode, $server['modes'], true),
        };
    }

    /** Сводка для подзаголовка: число серверов и игроков. @param list<array> $servers */
    public static function totals(array $servers): array
    {
        return ['servers' => count($servers), 'players' => array_sum(array_column($servers, 'players'))];
    }
}
