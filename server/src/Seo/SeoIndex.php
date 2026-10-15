<?php

namespace App\Seo;

use App\Geo\CountryDatabase;
use App\ServerList\ServerCategories;
use App\ServerList\ServerListPublisher;
use App\ServerList\ServerPopulationHistory;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Компактный снимок серверов для страниц под поисковики: мониторинг, карты, режимы, страница сервера.
 *
 * Полный список — мегабайты JSON, читать его на каждый заход робота нельзя. Индекс пишется
 * PHP-файлом (`return [...]`): OPcache держит его в общей памяти, и чтение почти бесплатно.
 * Пересобирается после каждого обновления списка (cron раз в 2 минуты).
 */
final class SeoIndex
{
    private const FILE = 'seo-index.php';
    /** Моды храним только у живых серверов: у пустых страница всё равно закрыта от индекса. */
    private const MAX_MODS = 80;

    /** Карты, у которых ключ не совпадает с привычным названием в адресе. */
    private const MAP_SLUGS = ['chernarusplus' => 'chernarus', 'enoch' => 'livonia', 'deerisle' => 'deer-isle', 'takistanplus' => 'takistan'];

    private ?array $loaded = null;

    public function __construct(
        private readonly string $directory,
        private readonly ServerCategories $categories,
    ) {
    }

    /** @param list<array> $servers видимые серверы из ответа лаунчерам, уже отсортированы по онлайну */
    public function write(array $servers, array $populationSummary, int $updatedAt): void
    {
        $entries = [];
        // Названия модов повторяются у сотен серверов (CF, Community Online Tools…) — храним их один раз.
        $modNames = [];
        foreach ($servers as $server) {
            $ip = (string) ($server['ip'] ?? '');
            $queryPort = (int) ($server['queryPort'] ?? $server['port'] ?? 0);
            if ($ip === '' || $queryPort <= 0) {
                continue;
            }
            $slug = self::serverSlug($ip, $queryPort);
            if (isset($entries[$slug])) {
                continue;
            }
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($server['displayName'] ?? $server['name'] ?? '')));
            $players = (int) ($server['players'] ?? 0);
            $population = $populationSummary['servers'][ServerPopulationHistory::key($server)] ?? null;
            $categories = $this->categories->classify($server);
            $mods = [];
            if ($players > 0 || ($population['average'] ?? 0) >= 1) {
                foreach (array_slice($server['modIds'] ?? [], 0, self::MAX_MODS) as $i => $id) {
                    $mods[] = $id = (string) $id;
                    $modNames[$id] ??= (string) ($server['mods'][$i] ?? "Workshop {$id}");
                }
            }
            $mapName = (string) ($server['mapName'] ?? $server['map'] ?? 'Chernarus');

            $entries[$slug] = [
                'slug' => $slug,
                'name' => $name !== '' ? $name : 'Без названия',
                'short' => ServerListPublisher::shortName($name) ?: $name,
                'ip' => $ip,
                'gamePort' => (int) ($server['gamePort'] ?? $server['port'] ?? $queryPort),
                'queryPort' => $queryPort,
                'map' => self::mapSlug((string) ($server['map'] ?? ''), $mapName),
                'mapName' => $mapName,
                'players' => $players,
                'maxPlayers' => (int) ($server['maxPlayers'] ?? 0),
                'version' => (string) ($server['version'] ?? ''),
                'perspective' => (string) ($server['perspective'] ?? ''),
                'password' => (bool) ($server['password'] ?? false),
                'sponsor' => (bool) ($server['sponsor'] ?? false),
                'country' => CountryDatabase::name((string) ($server['country'] ?? '')),
                'time' => (string) ($server['serverTime'] ?? ''),
                'modCount' => count($server['modIds'] ?? []),
                'mods' => $mods,
                'modes' => $categories['modes'],
                'style' => $categories['style'],
                'hardcore' => $categories['hardcore'],
                'population' => $population === null ? null : array_intersect_key($population,
                    array_flip(['ready', 'average', 'activePercent', 'day', 'night', 'coverage'])),
            ];
        }

        // Порядок «по онлайну» без учёта рекламы: страницы для поиска показывают живые серверы.
        uasort($entries, static fn (array $a, array $b) => $b['players'] <=> $a['players'] ?: strcmp($a['name'], $b['name']));

        (new Filesystem())->dumpFile(
            $this->directory . '/' . self::FILE,
            // var_export без отступов: файл вдвое меньше, OPcache компилирует его быстрее.
            '<?php return ' . preg_replace('/\n\s+/', '', var_export(['updatedAt' => $updatedAt, 'modNames' => $modNames, 'servers' => $entries], true)) . ';',
        );
        $this->loaded = null;
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->directory . '/' . self::FILE, true);
        }
    }

    /** @return array{updatedAt: int, servers: array<string, array>} */
    public function read(): array
    {
        if ($this->loaded === null) {
            $path = $this->directory . '/' . self::FILE;
            $data = is_file($path) ? include $path : null;
            $this->loaded = is_array($data) ? $data : ['updatedAt' => 0, 'modNames' => [], 'servers' => []];
        }

        return $this->loaded;
    }

    /** Сервер с модами в виде [id, название] для страницы. */
    public function server(string $slug): ?array
    {
        $index = $this->read();
        $server = $index['servers'][$slug] ?? null;
        if ($server !== null) {
            $server['mods'] = array_map(static fn (string $id) => [$id, $index['modNames'][$id] ?? "Workshop {$id}"], $server['mods']);
        }

        return $server;
    }

    /** @param callable(array): bool $filter @return list<array> */
    public function servers(callable $filter): array
    {
        return array_values(array_filter($this->read()['servers'], $filter));
    }

    /** @return array<string, array{slug: string, name: string, servers: int, players: int}> по убыванию числа серверов */
    public function maps(): array
    {
        $maps = [];
        foreach ($this->read()['servers'] as $server) {
            $map = &$maps[$server['map']];
            $map ??= ['slug' => $server['map'], 'name' => $server['mapName'], 'servers' => 0, 'players' => 0];
            $map['servers']++;
            $map['players'] += $server['players'];
            unset($map);
        }
        uasort($maps, static fn (array $a, array $b) => $b['servers'] <=> $a['servers']);

        return $maps;
    }

    /** Страница сервера стоит индексации, только если на нём играют: пустые серверы — тонкие страницы. */
    public static function isIndexable(array $server): bool
    {
        return $server['players'] > 0 || ($server['population']['average'] ?? 0) >= 1;
    }

    public static function serverSlug(string $ip, int $queryPort): string
    {
        return $ip . '-' . $queryPort;
    }

    public static function mapSlug(string $mapKey, string $mapName): string
    {
        return self::MAP_SLUGS[$mapKey] ?? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($mapName)), '-') ?: 'chernarus';
    }
}
