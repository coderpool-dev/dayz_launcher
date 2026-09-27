<?php
/**
 * Роутер для локального запуска встроенным сервером PHP:
 *
 *   php -S 127.0.0.1:8090 -t public dev-router.php
 *
 * Без него встроенный сервер сам отвечает 404 на адреса, похожие на файлы (например /download/1.2.3).
 * На проде эту роль выполняет nginx (try_files → index.php).
 */

$path = __DIR__ . '/public' . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== __DIR__ . '/public/' && is_file($path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/public/index.php';
