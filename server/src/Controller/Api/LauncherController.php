<?php

namespace App\Controller\Api;

use App\Release\ReleaseRepository;
use App\Stats\LauncherEvent;
use App\Stats\LauncherEventRecorder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * API для лаунчера: события (статистика) и проверка обновлений.
 * Лаунчер идентифицируется анонимным ID установки в заголовке x-launcher-guid.
 */
#[AsController]
final class LauncherController
{
    public function __construct(
        private readonly LauncherEventRecorder $recorder,
        private readonly ReleaseRepository $releases,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(service: 'limiter.launcher_events')]
        private readonly RateLimiterFactory $eventsLimiter,
    ) {
    }

    /**
     * POST /api/launcher/events
     * { "event": "start" | "heartbeat" | "play", "version": "1.1.0",
     *   "serverId": "...", "serverName": "...", "serverAddress": "ip:port" }
     */
    #[Route('/api/launcher/events', name: 'api_launcher_events', methods: ['POST'])]
    public function events(Request $request): Response
    {
        $ip = (string) $request->getClientIp();
        if (!$this->eventsLimiter->create($ip)->consume()->isAccepted()) {
            return new JsonResponse(['ok' => false, 'error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = json_decode($request->getContent(), true);
        $event = is_array($payload)
            ? LauncherEvent::fromRequest($payload, (string) $request->headers->get('x-launcher-guid', ''), $ip)
            : null;

        if ($event === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid event'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->recorder->record($event);
        } catch (UniqueConstraintViolationException) {
            // Одновременные события одной установки: запись уже сделана параллельным запросом.
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * GET /api/launcher/update?version=1.0.0 — есть ли версия новее установленной.
     */
    #[Route('/api/launcher/update', name: 'api_launcher_update', methods: ['GET'])]
    public function update(Request $request): Response
    {
        $current = (string) $request->query->get('version', '0.0.0');
        $latest = $this->releases->latestPublished();

        if ($latest === null || version_compare($latest->getVersion(), $current, '<=')) {
            return new JsonResponse(['updateAvailable' => false, 'latestVersion' => $latest?->getVersion()]);
        }

        return new JsonResponse([
            'updateAvailable' => true,
            'latestVersion' => $latest->getVersion(),
            'notes' => $latest->getNotes() ?? '',
            'url' => $this->urls->generate('download_release', ['version' => $latest->getVersion()], UrlGeneratorInterface::ABSOLUTE_URL),
            'sha256' => $latest->getSha256(),
            'size' => $latest->getFileSize(),
        ]);
    }
}
