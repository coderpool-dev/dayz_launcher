<?php

namespace App\Controller;

use App\Release\ReleaseRepository;
use App\Seo\MonitoringPages;
use App\Seo\SeoIndex;
use App\ServerList\ServerListStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Лендинг лаунчера: описание, скриншоты и кнопка скачивания последней версии.
 */
final class LandingController extends AbstractController
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly ServerListStore $serverList,
        private readonly SeoIndex $seoIndex,
    ) {
    }

    #[Route('/', name: 'landing', methods: ['GET'])]
    public function __invoke(): Response
    {
        $response = $this->render('landing/index.html.twig', [
            'release' => $this->releases->latestPublished(),
            'stats' => $this->serverList->readStats(),
        ]);

        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }

    /** Под запросы «dayz qe», «бег в наклоне»: зачем наклоны геймпада на Q/E и как их включить. */
    #[Route('/dayz-qe', name: 'guide_qe', methods: ['GET'])]
    public function guideQe(): Response
    {
        $response = $this->render('landing/guide_qe.html.twig', [
            'release' => $this->releases->latestPublished(),
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    /** Выбор между DZSA («красным лаунчером») и независимым PREPISKA. */
    #[Route('/krasnyy-launcher-dayz', name: 'guide_red_launcher', methods: ['GET'])]
    public function guideRedLauncher(): Response
    {
        $response = $this->render('landing/guide_red_launcher.html.twig', [
            'release' => $this->releases->latestPublished(),
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    /** Под запросы «dzsa launcher не работает»: чем PREPISKA отличается и почему грузит серверы без dayzsalauncher.com. */
    #[Route('/dzsa-launcher-ne-rabotaet', name: 'guide_dzsa', methods: ['GET'])]
    public function guideDzsa(): Response
    {
        $response = $this->render('landing/guide_dzsa.html.twig', [
            'release' => $this->releases->latestPublished(),
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    /** Под запросы «dzsa launcher», «dzsa launcher скачать»: что это за программа и где брать официальный установщик. */
    #[Route('/dzsa-launcher', name: 'guide_dzsa_launcher', methods: ['GET'])]
    public function guideDzsaLauncher(): Response
    {
        $response = $this->render('landing/guide_dzsa_launcher.html.twig', [
            'release' => $this->releases->latestPublished(),
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/monitoring', name: 'monitoring', methods: ['GET'])]
    public function monitoring(Request $request): Response
    {
        $servers = $this->seoIndex->servers(static fn () => true);
        $pageSize = 30;
        $pages = max(1, (int) ceil(count($servers) / $pageSize));
        $page = filter_var($request->query->all()['page'] ?? '1', FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > $pages) {
            throw $this->createNotFoundException();
        }
        return $this->render('landing/monitoring.html.twig', [
            'release' => $this->releases->latestPublished(),
            'servers' => array_slice($servers, ($page - 1) * $pageSize, $pageSize),
            'listingPage' => $page,
            'listingPages' => $pages,
            'listingPageSize' => $pageSize,
            'totals' => ['servers' => count($servers), 'players' => array_sum(array_column($servers, 'players'))],
            'updatedAt' => $this->seoIndex->read()['updatedAt'],
            'maps' => array_filter($this->seoIndex->maps(), static fn (array $map) => $map['servers'] >= MonitoringPages::MAP_MIN_SERVERS),
            'modes' => MonitoringPages::modes(),
        ]);
    }
}
