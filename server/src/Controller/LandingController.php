<?php

namespace App\Controller;

use App\Release\ReleaseRepository;
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
}
