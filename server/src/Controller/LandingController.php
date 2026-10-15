<?php

namespace App\Controller;

use App\Release\ReleaseRepository;
use App\Seo\MonitoringPages;
use App\Seo\SeoIndex;
use App\ServerList\ServerListStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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

    #[Route('/monitoring', name: 'monitoring', methods: ['GET'])]
    public function monitoring(): Response
    {
        return $this->render('landing/monitoring.html.twig', [
            'release' => $this->releases->latestPublished(),
            'servers' => array_slice($this->seoIndex->servers(static fn () => true), 0, 50),
            'maps' => array_filter($this->seoIndex->maps(), static fn (array $map) => $map['servers'] >= MonitoringPages::MAP_MIN_SERVERS),
            'modes' => MonitoringPages::modes(),
        ]);
    }
}
