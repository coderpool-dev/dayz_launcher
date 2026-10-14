<?php

namespace App\Controller;

use App\Release\ReleaseRepository;
use App\Seo\MonitoringPages;
use App\Seo\SeoIndex;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Страницы мониторинга, собранные на сервере: их видит поисковый робот без JavaScript.
 * Подборки по карте и режиму и страница каждого сервера — под запросы «сервера dayz namalsk»,
 * «pve сервер dayz», название конкретного сервера.
 */
final class MonitoringPagesController extends AbstractController
{
    /** Сколько серверов в таблице подборки. */
    private const LIST_LIMIT = 50;

    public function __construct(
        private readonly SeoIndex $index,
        private readonly ReleaseRepository $releases,
    ) {
    }

    #[Route('/monitoring/map/{map}', name: 'monitoring_map', requirements: ['map' => '[a-z0-9-]{1,40}'], methods: ['GET'])]
    public function map(string $map): Response
    {
        $info = $this->index->maps()[$map] ?? throw new NotFoundHttpException('Карта не найдена');
        $servers = $this->index->servers(static fn (array $server) => $server['map'] === $map);

        return $this->listing('map', $info['name'], $servers, [
            'h1' => "Серверы DayZ на карте {$info['name']}",
            'title' => "Серверы DayZ {$info['name']} — мониторинг, онлайн и IP",
            'about' => null,
            'indexable' => $info['servers'] >= MonitoringPages::MAP_MIN_SERVERS,
        ]);
    }

    #[Route('/monitoring/{mode}', name: 'monitoring_mode', requirements: ['mode' => 'pve|pvp|rp|deathmatch|survival|vanilla|modded|1pp'], methods: ['GET'])]
    public function mode(string $mode): Response
    {
        $page = MonitoringPages::modes()[$mode];
        $servers = $this->index->servers(static fn (array $server) => MonitoringPages::matchesMode($mode, $server));

        return $this->listing('mode', $mode, $servers, $page + ['indexable' => count($servers) > 0]);
    }

    #[Route('/monitoring/server/{slug}', name: 'monitoring_server', requirements: ['slug' => '[0-9a-f.:]{3,45}-[0-9]{1,5}'], methods: ['GET'])]
    public function server(string $slug): Response
    {
        $server = $this->index->server($slug);
        if ($server === null) {
            // Сервер выключили или он давно пустой — честный 404 со ссылкой на мониторинг.
            $response = $this->render('landing/server_missing.html.twig', ['release' => $this->releases->latestPublished()]);
            $response->setStatusCode(404);

            return $response;
        }

        $response = $this->render('landing/server.html.twig', [
            'release' => $this->releases->latestPublished(),
            'server' => $server,
            'indexable' => SeoIndex::isIndexable($server),
            'map' => $this->index->maps()[$server['map']] ?? null,
            'modes' => MonitoringPages::modes(),
            'updatedAt' => $this->index->read()['updatedAt'],
        ]);

        return $this->cached($response);
    }

    private function listing(string $kind, string $key, array $servers, array $page): Response
    {
        $response = $this->render('landing/monitoring_category.html.twig', [
            'release' => $this->releases->latestPublished(),
            'kind' => $kind,
            'key' => $key,
            'page' => $page,
            'servers' => array_slice($servers, 0, self::LIST_LIMIT),
            'totals' => MonitoringPages::totals($servers),
            'maps' => array_filter($this->index->maps(), static fn (array $map) => $map['servers'] >= MonitoringPages::MAP_MIN_SERVERS),
            'modes' => MonitoringPages::modes(),
            'updatedAt' => $this->index->read()['updatedAt'],
        ]);

        return $this->cached($response);
    }

    private function cached(Response $response): Response
    {
        $response->setPublic();
        $response->setMaxAge(120);

        return $response;
    }
}
