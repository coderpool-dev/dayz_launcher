<?php

namespace App\Controller\Api;

use App\ServerList\ServerCategories;
use App\ServerList\ServerListStore;
use App\ServerList\ServerPopulationHistory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MonitoringController
{
    public function __construct(
        private readonly ServerListStore $store,
        private readonly ServerCategories $categories,
        private readonly ServerPopulationHistory $history,
    ) {
    }

    #[Route('/api/monitoring', name: 'api_monitoring', methods: ['GET'])]
    public function list(): JsonResponse
    {
        ini_set('memory_limit', '512M');
        $path = $this->store->publicPayloadPath();
        if ($path === null) return new JsonResponse(['error' => 'Server list is not available yet'], 503);
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $summary = $this->history->summary();
        $fresh = !empty($summary['updatedAt']) && time() - $summary['updatedAt'] <= 3600;
        $servers = [];
        foreach ($payload['servers'] as $server) {
            $key = ServerPopulationHistory::key($server);
            $population = $summary['servers'][$key] ?? null;
            if ($population && !$fresh) {
                $population['ready'] = false;
                $population['day'] = $population['night'] = null;
            }
            $servers[] = array_intersect_key($server, array_flip(['name', 'ip', 'gamePort', 'port', 'queryPort', 'map', 'mapName',
                'players', 'maxPlayers', 'online', 'password', 'perspective', 'country', 'sponsor', 'version', 'mods', 'modIds'])) + [
                'key' => $key, 'modCount' => count($server['modIds'] ?? []),
                'categories' => $this->categories->classify($server), 'population' => $population,
            ];
        }
        return new JsonResponse(['servers' => $servers, 'timestamp' => $payload['meta']['storedAt'] ?? $payload['timestamp'],
            'history' => array_diff_key($summary, ['servers' => true]) + ['fresh' => $fresh]], headers: ['Cache-Control' => 'public, max-age=60']);
    }

    #[Route('/api/monitoring/history', name: 'api_monitoring_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $key = (string) $request->query->get('server', '');
        if (strlen($key) > 100 || !preg_match('/^.+:[1-9][0-9]{0,4}$/D', $key)) return new JsonResponse(['error' => 'Invalid server'], 400);
        $days = $request->query->getInt('days', 1) === 7 ? 7 : 1;
        return new JsonResponse(['points' => $this->history->series($key, $days, time()), 'days' => $days,
            'timezone' => 'Europe/Moscow'], headers: ['Cache-Control' => 'public, max-age=60']);
    }

    #[Route('/api/monitoring/summary', name: 'api_monitoring_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $summary = $this->history->summary();
        $summary['servers'] = (object) $summary['servers'];
        return new JsonResponse($summary, headers: ['Cache-Control' => 'public, max-age=60']);
    }
}
