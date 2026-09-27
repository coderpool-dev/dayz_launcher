<?php
/**
 * Роутер для локального запуска API встроенным сервером PHP:
 *
 *   php -S 127.0.0.1:8088 server/dev-router.php
 *
 * После этого API доступно по адресу http://127.0.0.1:8088/api/servers
 * (в лаунчере задайте переменную окружения PREPISKA_SERVERS_API_URL на этот адрес).
 */

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($requestPath === '/api/servers' || $requestPath === '/api/servers/') {
    require __DIR__ . '/api/servers.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
return true;
