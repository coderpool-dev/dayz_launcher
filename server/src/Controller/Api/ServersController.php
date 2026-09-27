<?php

namespace App\Controller\Api;

use App\ServerList\ServerListProvider;
use App\ServerList\ServerListPublisher;
use App\ServerList\ServerListStore;
use App\ServerList\ServerListUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/servers — объединённый список серверов DayZ для лаунчера.
 *
 * Параметры (необязательные): search/q — поиск по имени, IP, карте, стране и модам;
 * name — поиск только по имени; limit — максимум серверов (0 — все);
 * refresh=1&key=<SERVERS_REFRESH_KEY> — принудительно обновить список.
 */
#[AsController]
final class ServersController
{
    private const MAX_LIMIT = 5000;
    private const HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=60',
    ];

    public function __construct(
        private readonly ServerListProvider $provider,
        private readonly ServerListPublisher $publisher,
        private readonly ServerListStore $store,
        #[Autowire('%env(SERVERS_REFRESH_KEY)%')]
        private readonly string $refreshKey,
    ) {
    }

    #[Route('/api/servers', name: 'api_servers', methods: ['GET'])]
    #[Route('/api/servers/', name: 'api_servers_slash', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $search = trim((string) $request->query->get('search', $request->query->get('q', '')));
        $nameSearch = trim((string) $request->query->get('name', ''));
        $limit = max(0, min(self::MAX_LIMIT, $request->query->getInt('limit', $nameSearch !== '' ? 50 : 0)));

        try {
            $cacheStatus = $this->provider->ensureFresh($this->isRefreshAuthorized($request));
        } catch (ServerListUnavailableException $e) {
            return $this->error($e->getMessage(), $e->httpStatus, $e->errors);
        }

        $headers = self::HEADERS + ['X-Proxy-Cache' => $cacheStatus];

        // Запрос лаунчера (без параметров) — отдаём заранее собранный ответ.
        if ($search === '' && $nameSearch === '' && $limit === 0) {
            $path = $this->store->publicPayloadPath();
            if ($path === null) {
                $this->publisher->publish();
                $path = $this->store->publicPayloadPath();
            }

            if ($path !== null) {
                $response = new BinaryFileResponse($path, Response::HTTP_OK, $headers + ['Content-Type' => 'application/json; charset=utf-8'], autoEtag: true, autoLastModified: true);
                $response->isNotModified($request);

                return $response;
            }
        }

        $snapshot = $this->publisher->loadSnapshot($cacheStatus);
        if ($snapshot === null) {
            return $this->error('Server list is not available yet', Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse($this->publisher->buildPayload($snapshot, $search, $nameSearch, $limit), Response::HTTP_OK, $headers);
    }

    private function isRefreshAuthorized(Request $request): bool
    {
        return $request->query->get('refresh') === '1'
            && $this->refreshKey !== ''
            && hash_equals($this->refreshKey, (string) $request->query->get('key', ''));
    }

    private function error(string $message, int $status, array $errors = []): Response
    {
        return new JsonResponse(['success' => false, 'source' => 'goida', 'cache' => false, 'error' => $message, 'errors' => $errors], $status, self::HEADERS);
    }
}
