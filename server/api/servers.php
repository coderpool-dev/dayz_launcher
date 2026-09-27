<?php

/**
 * GET /api/servers — объединённый список серверов DayZ (DZSA + BattleMetrics) для лаунчера.
 *
 * Параметры запроса (все необязательные):
 *   search / q  — поиск по имени, IP, карте, стране и модам;
 *   name        — поиск только по имени;
 *   limit       — максимум серверов в ответе (0 — без ограничения);
 *   refresh=1&key=... — принудительно обновить кэш (только с ключом refresh_key из config.local.php).
 *
 * Ответ кэшируется в ../cache на $cacheTtl секунд. Параметры, влияющие на нагрузку
 * (глубина BattleMetrics, бюджет времени), задаются только на сервере.
 */

declare(strict_types=1);

ini_set('memory_limit', '512M');
set_time_limit(45);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

$dzsaUrls = [
    'https://dayzsalauncher.com/api/v2/launcher/servers/dayz',
    'https://dayzsalauncher.com/api/v2/launcher/listings/dayz',
];

$battlemetricsUrl = 'https://api.battlemetrics.com/servers';

/**
 * Локальные настройки сервера (не хранятся в git): server/config.local.php.
 * Пример — server/config.example.php.
 */
$localConfigFile = __DIR__ . '/../config.local.php';
$localConfig = is_file($localConfigFile) ? (array)(require $localConfigFile) : [];

/**
 * BattleMetrics API доступен только с токеном аккаунта с подпиской.
 * Без токена этот источник пропускается.
 */
$battlemetricsToken = trim((string)(getenv('BATTLEMETRICS_TOKEN') ?: ($localConfig['battlemetrics_token'] ?? '')));

$cacheDir = __DIR__ . '/../cache';
$cacheFile = $cacheDir . '/dayz-servers-normalized.json';
$zeroPlayerStateFile = $cacheDir . '/dayz-zero-player-state.json';
$lockFile = $cacheDir . '/dayz-servers-normalized.lock';

$cacheTtl = 60;
$staleTtl = 60 * 60 * 6;
$zeroPlayerHideSeconds = 30 * 60;
$zeroPlayerStatePruneSeconds = 7 * 24 * 60 * 60;

$battlemetricsMaxPages = 100;
$maxSeconds = 25;

$defaultLimit = 0;
$maxLimit = 5000;

/**
 * Ключ для принудительного обновления кэша (?refresh=1&key=...). Пустой — обновление по запросу отключено.
 */
$refreshKey = trim((string)($localConfig['refresh_key'] ?? ''));

/**
 * Фейковые «зеркала» популярных серверов: известные подсети, высокие порты
 * и завышенное число слотов. Такие серверы не попадают в выдачу.
 */
$mirrorSubnets = ['31.77.142.', '31.76.54.'];
$mirrorMinPort = 30000;
$mirrorMinMaxPlayers = 120;

/**
 * Короткие отображаемые имена: если название сервера начинается с префикса,
 * в поле displayName отдаётся только префикс.
 */
$displayNamePrefixes = [
    'Russian Classic Deathmatch',
];

/**
 * Спонсорские серверы проекта — только у них будет sponsor=true.
 * Ключ — ip:port, где port может быть как queryPort, так и gamePort.
 */
$sponsorEndpoints = [
    '194.147.90.110:2320' => true,
];

$sponsorIps = [
    // Только если все серверы на этом IP должны быть sponsor=true.
    // '185.207.214.16' => true,
];

$startedAt = microtime(true);

$search = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));
$nameSearch = trim((string)($_GET['name'] ?? ''));

$limit = max(
    0,
    min(
        $maxLimit,
        (int)($_GET['limit'] ?? ($nameSearch !== '' ? 50 : $defaultLimit))
    )
);

$forceRefresh = ($_GET['refresh'] ?? '') === '1'
    && $refreshKey !== ''
    && hash_equals($refreshKey, (string)($_GET['key'] ?? ''));

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

/**
 * 1. Свежий кэш.
 */
if (!$forceRefresh && isFreshCache($cacheFile, $cacheTtl)) {
    $cached = readCache($cacheFile);

    if ($cached !== null) {
        header('X-Proxy-Cache: hit');

        echo encodePayload(
            filterAndLimitServers($cached['servers'], $search, $limit, $nameSearch),
            $cached['errors'] ?? [],
            true,
            cacheMeta($cached, 'hit', $cacheFile, $nameSearch, $search, $limit)
        );

        exit;
    }
}

/**
 * 2. Lock, чтобы несколько запросов не обновляли кэш одновременно.
 */
$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false) {
    serveStaleOrFail($cacheFile, $search, $limit, 'Could not open lock file', [], $nameSearch);
}

$gotLock = flock($lockHandle, LOCK_EX | LOCK_NB);

if (!$gotLock) {
    $cached = readCache($cacheFile);

    if ($cached !== null && isStaleAllowed($cacheFile, $staleTtl)) {
        header('X-Proxy-Cache: stale-lock');

        echo encodePayload(
            filterAndLimitServers($cached['servers'], $search, $limit, $nameSearch),
            array_merge(
                $cached['errors'] ?? [],
                [[
                    'source' => 'proxy',
                    'error' => 'Another request is refreshing cache, stale cache served',
                ]]
            ),
            true,
            cacheMeta($cached, 'stale-lock', $cacheFile, $nameSearch, $search, $limit)
        );

        exit;
    }

    http_response_code(503);

    echo json_encode([
        'success' => false,
        'source' => 'goida',
        'cache' => false,
        'error' => 'Cache is being refreshed and no stale cache is available',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

/**
 * 3. После lock ещё раз проверяем кэш.
 */
if (!$forceRefresh && isFreshCache($cacheFile, $cacheTtl)) {
    $cached = readCache($cacheFile);

    if ($cached !== null) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        header('X-Proxy-Cache: hit-after-lock');

        echo encodePayload(
            filterAndLimitServers($cached['servers'], $search, $limit, $nameSearch),
            $cached['errors'] ?? [],
            true,
            cacheMeta($cached, 'hit-after-lock', $cacheFile, $nameSearch, $search, $limit)
        );

        exit;
    }
}

$errors = [];
$servers = [];
$seen = [];

$meta = [
    'cacheStatus' => 'refreshed',
    'truncated' => false,
    'timeBudgetSeconds' => $maxSeconds,
    'battlemetricsMaxPages' => $battlemetricsMaxPages,
    'battlemetricsPagesFetched' => 0,
    'battlemetricsHasMore' => false,
    'dzsaFetched' => 0,
    'nameSearch' => $nameSearch,
    'search' => $search,
    'limit' => $limit,
];

/**
 * 4. DZSA.
 */
foreach ($dzsaUrls as $url) {
    if (timeBudgetExceeded($startedAt, $maxSeconds)) {
        $meta['truncated'] = true;

        $errors[] = [
            'source' => 'proxy',
            'url' => $url,
            'error' => 'Time budget exceeded before DZSA fetch completed',
        ];

        break;
    }

    $json = fetchJson($url, $errors, secondsLeft($startedAt, $maxSeconds));

    foreach (extractArray($json) as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $server = normalizeDzsaServer($raw);

        if ($server === null) {
            continue;
        }

        addServer($servers, $seen, $server);
        $meta['dzsaFetched']++;
    }
}

/**
 * 5. BattleMetrics (только при наличии токена).
 */
$meta['battlemetricsEnabled'] = $battlemetricsToken !== '';

$bmResult = !$meta['battlemetricsEnabled']
    ? ['items' => [], 'pagesFetched' => 0, 'hasMore' => false, 'truncated' => false]
    : fetchBattlemetricsPages(
        $battlemetricsUrl,
        $battlemetricsToken,
        [
            'filter[game]' => 'dayz',
            'filter[status]' => 'online',
            'page[size]' => '100',
            'sort' => '-players',
        ],
        $battlemetricsMaxPages,
        $errors,
        $startedAt,
        $maxSeconds
    );

$meta['battlemetricsPagesFetched'] = $bmResult['pagesFetched'];
$meta['battlemetricsHasMore'] = $bmResult['hasMore'];

if ($bmResult['truncated']) {
    $meta['truncated'] = true;
}

foreach ($bmResult['items'] as $raw) {
    if (!is_array($raw)) {
        continue;
    }

    $server = normalizeBattlemetricsServer($raw);

    if ($server !== null && !serverExists($seen, $server)) {
        addServer($servers, $seen, $server);
    }
}

updateZeroPlayerState($servers, $zeroPlayerStateFile, $zeroPlayerStatePruneSeconds);

$servers = sortServers($servers);

if (count($servers) === 0) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    serveStaleOrFail(
        $cacheFile,
        $search,
        $limit,
        'No servers fetched from upstream sources',
        $errors,
        $nameSearch
    );
}

/**
 * 6. Сохраняем полный неотфильтрованный список.
 */
$storedMeta = array_merge($meta, [
    'cacheStatus' => 'stored',
    'durationSeconds' => round(microtime(true) - $startedAt, 3),
]);

atomicWrite($cacheFile, encodePayload($servers, $errors, false, $storedMeta));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

header('X-Proxy-Cache: refreshed');

echo encodePayload(
    filterAndLimitServers($servers, $search, $limit, $nameSearch),
    $errors,
    false,
    array_merge($meta, [
        'cacheStatus' => 'refreshed',
        'durationSeconds' => round(microtime(true) - $startedAt, 3),
    ])
);

exit;

function fetchJson(string $url, array &$errors, int $timeoutSeconds = 15, array $extraHeaders = [])
{
    $timeoutSeconds = max(2, min(20, $timeoutSeconds));
    $connectTimeout = max(2, min(6, $timeoutSeconds));

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json',
            'User-Agent: DZSALauncher-Proxy/3.2',
        ], $extraHeaders),
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);

    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        $errors[] = [
            'url' => $url,
            'status' => $httpCode,
            'curl_errno' => $errno,
            'error' => $error !== '' ? $error : (apiErrorDetail($response) ?? 'HTTP request failed'),
        ];

        return null;
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        $errors[] = [
            'url' => $url,
            'status' => $httpCode,
            'error' => 'Invalid JSON',
        ];

        return null;
    }

    return $json;
}

function fetchBattlemetricsPages(
    string $baseUrl,
    string $token,
    array $query,
    int $maxPages,
    array &$errors,
    float $startedAt,
    int $maxSeconds
): array {
    $items = [];
    $nextUrl = $baseUrl . '?' . http_build_query($query);
    $pagesFetched = 0;
    $hasMore = false;
    $truncated = false;

    for ($page = 0; $page < $maxPages && $nextUrl !== ''; $page++) {
        if (timeBudgetExceeded($startedAt, $maxSeconds)) {
            $truncated = true;

            $errors[] = [
                'source' => 'battlemetrics',
                'status' => 206,
                'error' => 'Time budget exceeded while fetching BattleMetrics pages',
                'pagesFetched' => $pagesFetched,
            ];

            break;
        }

        $json = fetchJson($nextUrl, $errors, secondsLeft($startedAt, $maxSeconds), ["Authorization: Bearer {$token}"]);

        if (!is_array($json)) {
            break;
        }

        foreach (extractArray($json['data'] ?? null) as $raw) {
            $items[] = $raw;
        }

        $pagesFetched++;
        $nextUrl = '';

        if (!empty($json['links']['next']) && is_string($json['links']['next'])) {
            $nextUrl = $json['links']['next'];
        }
    }

    if ($nextUrl !== '') {
        $hasMore = true;

        if ($pagesFetched >= $maxPages) {
            $truncated = true;

            $errors[] = [
                'source' => 'battlemetrics',
                'status' => 206,
                'error' => 'BattleMetrics page limit reached before all servers were fetched',
                'pagesFetched' => $pagesFetched,
                'maxPages' => $maxPages,
            ];
        }
    }

    return [
        'items' => $items,
        'pagesFetched' => $pagesFetched,
        'hasMore' => $hasMore,
        'truncated' => $truncated,
    ];
}

/**
 * Текст ошибки из JSON-ответа API: { "errors": [{ "detail": "..." }] } или { "error": "..." }.
 */
function apiErrorDetail($response): ?string
{
    if (!is_string($response) || $response === '') {
        return null;
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        return null;
    }

    $detail = $json['errors'][0]['detail'] ?? $json['errors'][0]['title'] ?? $json['error'] ?? $json['message'] ?? null;

    return is_string($detail) && $detail !== '' ? $detail : null;
}

function normalizeDzsaServer(array $raw): ?array
{
    $endpoint = is_array($raw['endpoint'] ?? null) ? $raw['endpoint'] : [];

    $ip = trim(strval($endpoint['ip'] ?? $raw['ip'] ?? ''));
    $queryPort = intval($endpoint['port'] ?? $raw['queryPort'] ?? $raw['port'] ?? 0);
    $gamePort = intval($raw['gamePort'] ?? $raw['game_port'] ?? $queryPort);

    if ($ip === '' || $queryPort <= 0 || $gamePort <= 0) {
        return null;
    }

    [$modIds, $mods] = normalizeDzsaMods($raw['mods'] ?? []);

    $name = strval($raw['name'] ?? 'Unknown');
    $map = strval($raw['map'] ?? $raw['mission'] ?? detectMap($name));
    $serverTime = strval($raw['time'] ?? '');

    /**
     * ВАЖНО:
     * DZSA raw['sponsor'] полностью игнорируется.
     * sponsor=true ставится только серверам из $sponsorEndpoints / $sponsorIps.
     */
    $sponsor = isSponsorServer($ip, $queryPort, $gamePort);
    $maxPlayers = intval($raw['maxPlayers'] ?? $raw['max_players'] ?? 0);

    if (isMirrorServer($ip, $queryPort, $gamePort, $maxPlayers)) {
        return null;
    }

    return [
        'id' => stableId($ip, $queryPort),
        'source' => 'dzsa',
        'name' => $name,
        'displayName' => displayNameFor($name),
        'ip' => $ip,
        'port' => $gamePort,
        'gamePort' => $gamePort,
        'queryPort' => $queryPort,
        'map' => mapKey($map),
        'mapName' => mapName($map),
        'players' => intval($raw['players'] ?? 0),
        'maxPlayers' => $maxPlayers,
        'ping' => 0,
        'version' => strval($raw['version'] ?? ''),
        'online' => true,
        'password' => boolval($raw['password'] ?? false),
        'vac' => boolval($raw['vac'] ?? true),
        'perspective' => !empty($raw['firstPersonOnly']) ? '1pp' : '3pp',
        'time' => isClock($serverTime) ? $serverTime : '-',
        'serverTime' => isClock($serverTime) ? $serverTime : '',
        'mode' => count($modIds) > 0 ? 'modded' : 'community',
        'mods' => $mods,
        'modIds' => $modIds,
        'modsSource' => count($modIds) > 0 ? 'dzsa' : '',
        'hive' => 'Private',
        'battleye' => boolval($raw['battlEye'] ?? $raw['battleye'] ?? true),

        // sponsor определяется только нашим списком.
        'sponsor' => $sponsor,

        /**
         * DZSA profile/staticName не используем,
         * чтобы чужие promoted/profile сервера не влияли на выдачу.
         */
        'profile' => false,
        'staticName' => false,
    ];
}

function normalizeBattlemetricsServer(array $raw): ?array
{
    $attrs = is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];
    $details = is_array($attrs['details'] ?? null) ? $attrs['details'] : [];

    $ip = trim(strval($attrs['ip'] ?? ''));

    $queryPort = intval(
        $details['queryPort']
        ?? $details['query_port']
        ?? $attrs['portQuery']
        ?? $attrs['queryPort']
        ?? $attrs['port']
        ?? 0
    );

    $gamePort = intval(
        $details['gamePort']
        ?? $details['game_port']
        ?? $details['port']
        ?? $attrs['gamePort']
        ?? $attrs['port']
        ?? $queryPort
    );

    if ($ip === '' || $queryPort <= 0 || $gamePort <= 0) {
        return null;
    }

    $modIds = stringArray($details['modIds'] ?? []);
    $mods = stringArray($details['modNames'] ?? []);
    $name = strval($attrs['name'] ?? 'Unknown');
    $map = strval($details['map'] ?? detectMap($name));
    $time = strval($details['time'] ?? '-');
    $maxPlayers = intval($attrs['maxPlayers'] ?? 60);

    if (isMirrorServer($ip, $queryPort, $gamePort, $maxPlayers)) {
        return null;
    }

    return [
        'id' => intval($raw['id'] ?? stableId($ip, $queryPort)),
        'source' => 'battlemetrics',
        'name' => $name,
        'displayName' => displayNameFor($name),
        'ip' => $ip,
        'port' => $gamePort,
        'gamePort' => $gamePort,
        'queryPort' => $queryPort,
        'map' => mapKey($map),
        'mapName' => mapName($map),
        'players' => intval($attrs['players'] ?? 0),
        'maxPlayers' => $maxPlayers,
        'ping' => 0,
        'version' => strval($details['version'] ?? ''),
        'online' => strtolower(strval($attrs['status'] ?? 'online')) === 'online',
        'password' => boolval($details['password'] ?? false),
        'vac' => true,
        'perspective' => !empty($details['third_person']) ? '3pp' : '1pp',
        'time' => $time,
        'serverTime' => $time !== '-' ? $time : '',
        'mode' => count($modIds) > 0 ? 'modded' : 'community',
        'mods' => $mods,
        'modIds' => $modIds,
        'modsSource' => count($modIds) > 0 ? 'battlemetrics' : '',
        'hive' => 'Private',
        'battleye' => true,
        'rank' => intval($attrs['rank'] ?? 0),
        'country' => strval($attrs['country'] ?? ''),

        // sponsor определяется только нашим списком.
        'sponsor' => isSponsorServer($ip, $queryPort, $gamePort),
        'profile' => false,
        'staticName' => false,
    ];
}

function isMirrorServer(string $ip, int $queryPort, int $gamePort, int $maxPlayers): bool
{
    global $mirrorSubnets, $mirrorMinPort, $mirrorMinMaxPlayers;

    foreach ($mirrorSubnets as $subnet) {
        if (str_starts_with($ip, $subnet)) {
            return $gamePort >= $mirrorMinPort
                && $queryPort >= $mirrorMinPort
                && $maxPlayers >= $mirrorMinMaxPlayers;
        }
    }

    return false;
}

function displayNameFor(string $name): string
{
    global $displayNamePrefixes;

    foreach ($displayNamePrefixes as $prefix) {
        if (stripos($name, $prefix) === 0) {
            return $prefix;
        }
    }

    return $name;
}

function isSponsorServer(string $ip, int $queryPort, int $gamePort): bool
{
    global $sponsorEndpoints, $sponsorIps;

    if (isset($sponsorIps[$ip])) {
        return true;
    }

    foreach ([$queryPort, $gamePort] as $port) {
        if ($port > 0 && isset($sponsorEndpoints["{$ip}:{$port}"])) {
            return true;
        }
    }

    return false;
}

function normalizeDzsaMods($modsRaw): array
{
    $ids = [];
    $names = [];

    if (!is_array($modsRaw)) {
        return [$ids, $names];
    }

    foreach ($modsRaw as $mod) {
        if (!is_array($mod)) {
            continue;
        }

        $id = strval(
            $mod['steamWorkshopId']
            ?? $mod['steam_workshop_id']
            ?? $mod['workshopId']
            ?? $mod['id']
            ?? ''
        );

        // Дедупликация по ID, чтобы имена оставались на тех же позициях, что и ID.
        if ($id === '' || !ctype_digit($id) || in_array($id, $ids, true)) {
            continue;
        }

        $ids[] = $id;
        $names[] = trim(strval($mod['name'] ?? '')) ?: "Workshop {$id}";
    }

    return [$ids, $names];
}

function encodePayload(array $servers, array $errors, bool $cache, array $meta = []): string
{
    $payload = [
        'success' => count($servers) > 0,
        'source' => 'goida',
        'cache' => $cache,
        'timestamp' => time(),
        'count' => count($servers),
        'stats' => buildStats($servers),
        'meta' => $meta,
        'errors' => $errors,
        'servers' => $servers,
    ];

    $response = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($response === false) {
        http_response_code(500);
        return '{"success":false,"error":"json_encode failed"}';
    }

    return $response;
}

function readCache(string $cacheFile): ?array
{
    if (!is_file($cacheFile)) {
        return null;
    }

    $raw = file_get_contents($cacheFile);

    if ($raw === false || $raw === '') {
        return null;
    }

    $json = json_decode($raw, true);

    if (!is_array($json) || !isset($json['servers']) || !is_array($json['servers'])) {
        return null;
    }

    return $json;
}

function cacheMeta(array $cached, string $status, string $cacheFile, string $nameSearch, string $search, int $limit): array
{
    return [
        'cacheStatus' => $status,
        'cacheAge' => is_file($cacheFile) ? time() - filemtime($cacheFile) : null,
        'truncated' => $cached['meta']['truncated'] ?? false,
        'battlemetricsPagesFetched' => $cached['meta']['battlemetricsPagesFetched'] ?? 0,
        'battlemetricsHasMore' => $cached['meta']['battlemetricsHasMore'] ?? false,
        'nameSearch' => $nameSearch,
        'search' => $search,
        'limit' => $limit,
    ];
}

function serveStaleOrFail(
    string $cacheFile,
    string $search,
    int $limit,
    string $reason,
    array $errors = [],
    string $nameSearch = ''
): void {
    $cached = readCache($cacheFile);

    if ($cached !== null) {
        header('X-Proxy-Cache: stale-error');

        echo encodePayload(
            filterAndLimitServers($cached['servers'], $search, $limit, $nameSearch),
            array_merge(
                $cached['errors'] ?? [],
                $errors,
                [[
                    'source' => 'proxy',
                    'error' => $reason,
                ]]
            ),
            true,
            cacheMeta($cached, 'stale-error', $cacheFile, $nameSearch, $search, $limit)
        );

        exit;
    }

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'source' => 'goida',
        'cache' => false,
        'error' => $reason,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}


function updateZeroPlayerState(array $servers, string $stateFile, int $pruneAfterSeconds): void
{
    $now = time();
    $state = readZeroPlayerState($stateFile);
    $seen = [];

    foreach ($servers as $server) {
        $key = serverVisibilityKey($server);

        if ($key === '') {
            continue;
        }

        $seen[$key] = true;
        $players = intval($server['players'] ?? 0);

        if ($players > 0) {
            unset($state[$key]);
            continue;
        }

        $zeroSince = isset($state[$key]['zeroSince']) ? intval($state[$key]['zeroSince']) : $now;
        $state[$key] = [
            'zeroSince' => $zeroSince > 0 ? $zeroSince : $now,
            'lastSeen' => $now,
        ];
    }

    foreach ($state as $key => $entry) {
        $lastSeen = isset($entry['lastSeen']) ? intval($entry['lastSeen']) : 0;

        if (!isset($seen[$key]) && ($lastSeen <= 0 || $now - $lastSeen > $pruneAfterSeconds)) {
            unset($state[$key]);
        }
    }

    atomicWrite($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
}

function filterZeroPlayerServers(array $servers, string $stateFile, int $hideAfterSeconds): array
{
    $now = time();
    $state = readZeroPlayerState($stateFile);

    return array_values(array_filter($servers, function (array $server) use ($state, $now, $hideAfterSeconds): bool {
        if (intval($server['players'] ?? 0) > 0) {
            return true;
        }

        $key = serverVisibilityKey($server);

        if ($key === '' || !isset($state[$key]['zeroSince'])) {
            return true;
        }

        return $now - intval($state[$key]['zeroSince']) < $hideAfterSeconds;
    }));
}

function readZeroPlayerState(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return [];
    }

    $raw = file_get_contents($stateFile);

    if ($raw === false || $raw === '') {
        return [];
    }

    $json = json_decode($raw, true);

    return is_array($json) ? $json : [];
}

function serverVisibilityKey(array $server): string
{
    $keys = endpointKeys($server);

    if (count($keys) > 0) {
        return $keys[0];
    }

    $id = trim(strval($server['id'] ?? ''));

    return $id !== '' ? "id:{$id}" : '';
}

function filterAndLimitServers(array $servers, string $search, int $limit, string $nameSearch = ''): array
{
    global $zeroPlayerStateFile, $zeroPlayerHideSeconds;

    $servers = filterZeroPlayerServers($servers, $zeroPlayerStateFile, $zeroPlayerHideSeconds);

    $searchLower = mb_strtolower($search);
    $nameSearchLower = mb_strtolower($nameSearch);

    if ($nameSearchLower !== '') {
        $servers = array_values(array_filter($servers, function (array $server) use ($nameSearchLower): bool {
            return containsText(mb_strtolower(strval($server['name'] ?? '')), $nameSearchLower);
        }));
    } elseif ($searchLower !== '') {
        $servers = array_values(array_filter($servers, function (array $server) use ($searchLower): bool {
            return serverMatchesSearch($server, $searchLower);
        }));
    }

    $servers = sortServers($servers);

    if ($limit > 0) {
        $servers = array_slice($servers, 0, $limit);
    }

    return $servers;
}

function sortServers(array $servers): array
{
    usort($servers, 'compareServers');
    return $servers;
}

function compareServers(array $a, array $b): int
{
    /**
     * Сначала спонсорские серверы, затем по онлайну, рангу и имени.
     */
    $sponsorCompare = intval(!empty($b['sponsor'])) <=> intval(!empty($a['sponsor']));

    if ($sponsorCompare !== 0) {
        return $sponsorCompare;
    }

    $playersCompare = intval($b['players'] ?? 0) <=> intval($a['players'] ?? 0);

    if ($playersCompare !== 0) {
        return $playersCompare;
    }

    $rankA = intval($a['rank'] ?? 999999999);
    $rankB = intval($b['rank'] ?? 999999999);
    $rankCompare = $rankA <=> $rankB;

    if ($rankCompare !== 0) {
        return $rankCompare;
    }

    return strcmp(strval($a['name'] ?? ''), strval($b['name'] ?? ''));
}

function extractArray($json): array
{
    if (!is_array($json)) {
        return [];
    }

    if (isListArray($json)) {
        return $json;
    }

    foreach (['servers', 'result', 'data', 'items'] as $key) {
        if (isset($json[$key]) && is_array($json[$key])) {
            return $json[$key];
        }
    }

    return [];
}

function addServer(array &$servers, array &$seen, array $server): void
{
    $servers[] = $server;

    foreach (endpointKeys($server) as $key) {
        $seen[$key] = true;
    }
}

function serverExists(array $seen, array $server): bool
{
    foreach (endpointKeys($server) as $key) {
        if (isset($seen[$key])) {
            return true;
        }
    }

    return false;
}

function endpointKeys(array $server): array
{
    $ip = trim(strval($server['ip'] ?? ''));

    if ($ip === '') {
        return [];
    }

    $ports = array_unique(array_filter([
        intval($server['queryPort'] ?? 0),
        intval($server['gamePort'] ?? 0),
        intval($server['port'] ?? 0),
    ], 'isPositivePort'));

    $keys = [];

    foreach ($ports as $port) {
        $keys[] = "{$ip}:{$port}";
    }

    return $keys;
}

function buildStats(array $servers): array
{
    $online = 0;
    $players = 0;

    foreach ($servers as $server) {
        if (!empty($server['online'])) {
            $online++;
        }

        $players += intval($server['players'] ?? 0);
    }

    return [
        'totalServers' => count($servers),
        'onlineServers' => $online,
        'totalPlayers' => $players,
        'avgPlayers' => $online > 0 ? (int)round($players / $online) : 0,
    ];
}

function serverMatchesSearch(array $server, string $search): bool
{
    foreach (['name', 'ip', 'mapName', 'map', 'country'] as $field) {
        if (containsText(mb_strtolower(strval($server[$field] ?? '')), $search)) {
            return true;
        }
    }

    foreach (['mods', 'modIds'] as $field) {
        if (!is_array($server[$field] ?? null)) {
            continue;
        }

        foreach ($server[$field] as $value) {
            if (containsText(mb_strtolower(strval($value)), $search)) {
                return true;
            }
        }
    }

    return false;
}

function stringArray($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $item) {
        $text = trim(strval($item));

        if ($text !== '') {
            $result[] = $text;
        }
    }

    return array_values(array_unique($result));
}

function isFreshCache(string $cacheFile, int $cacheTtl): bool
{
    return is_file($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl;
}

function isStaleAllowed(string $cacheFile, int $staleTtl): bool
{
    return is_file($cacheFile) && time() - filemtime($cacheFile) < $staleTtl;
}

function atomicWrite(string $file, string $content): void
{
    $tmp = $file . '.tmp.' . getmypid();

    file_put_contents($tmp, $content, LOCK_EX);
    rename($tmp, $file);
}

function secondsLeft(float $startedAt, int $maxSeconds): int
{
    $left = $maxSeconds - (int)floor(microtime(true) - $startedAt);

    return max(2, min(20, $left));
}

function timeBudgetExceeded(float $startedAt, int $maxSeconds): bool
{
    return microtime(true) - $startedAt >= $maxSeconds;
}

function stableId(string $ip, int $queryPort): int
{
    return 1000000000 + (int)sprintf('%u', crc32("dzsa:{$ip}:{$queryPort}")) % 1000000000;
}

function isClock(string $value): bool
{
    return preg_match('/^\d{2}:\d{2}$/', $value) === 1;
}

function detectMap(string $name): string
{
    $lower = mb_strtolower($name);

    if (containsText($lower, 'livonia') || containsText($lower, 'enoch')) {
        return 'enoch';
    }

    if (containsText($lower, 'namalsk')) {
        return 'namalsk';
    }

    if (containsText($lower, 'deer isle') || containsText($lower, 'deerisle')) {
        return 'deerisle';
    }

    if (containsText($lower, 'takistan')) {
        return 'takistanplus';
    }

    if (containsText($lower, 'sakhal')) {
        return 'sakhal';
    }

    return 'chernarusplus';
}

function mapKey(string $map): string
{
    $lower = mb_strtolower(trim($map));

    return match ($lower) {
        'chernarus' => 'chernarusplus',
        'livonia' => 'enoch',
        'deer isle' => 'deerisle',
        'takistan' => 'takistanplus',
        '' => 'chernarusplus',
        default => $lower,
    };
}

function mapName(string $map): string
{
    return match (mapKey($map)) {
        'chernarusplus' => 'Chernarus',
        'enoch' => 'Livonia',
        'deerisle' => 'Deer Isle',
        'namalsk' => 'Namalsk',
        'takistanplus' => 'Takistan',
        'sakhal' => 'Sakhal',
        default => $map !== '' ? ucfirst($map) : 'Chernarus',
    };
}

function isListArray(array $array): bool
{
    $expected = 0;

    foreach ($array as $key => $_) {
        if ($key !== $expected) {
            return false;
        }

        $expected++;
    }

    return true;
}

function isPositivePort(int $port): bool
{
    return $port > 0;
}

function containsText(string $haystack, string $needle): bool
{
    return $needle === '' || mb_strpos($haystack, $needle) !== false;
}