<?php

namespace App\Controller;

use App\Release\ReleaseRepository;
use App\Release\ReleaseStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Скачивание установщиков лаунчера.
 */
#[AsController]
final class DownloadController
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly ReleaseStorage $storage,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Постоянная ссылка на последнюю версию — для сайта, Discord и т.п. */
    #[Route('/download', name: 'download_latest', methods: ['GET'])]
    public function latest(): Response
    {
        $latest = $this->releases->latestPublished() ?? throw new NotFoundHttpException('Нет опубликованных версий.');

        return new RedirectResponse($this->urls->generate('download_release', ['version' => $latest->getVersion()]));
    }

    #[Route('/download/{version}', name: 'download_release', requirements: ['version' => '\d+\.\d+\.\d+'], methods: ['GET'])]
    public function release(string $version): Response
    {
        $release = $this->releases->findPublishedByVersion($version);
        if ($release === null || !$release->hasFile() || !is_file($path = $this->storage->path($release->getFileName()))) {
            throw new NotFoundHttpException('Версия не найдена.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $release->getFileName());
        $response->headers->set('X-Content-SHA256', $release->getSha256());

        return $response;
    }
}
