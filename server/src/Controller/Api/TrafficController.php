<?php

namespace App\Controller\Api;

use App\Traffic\BrowserTraffic;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TrafficController
{
    #[Route('/api/traffic/confirm', name: 'api_traffic_confirm', methods: ['POST'])]
    public function confirm(Request $request, BrowserTraffic $traffic): JsonResponse
    {
        return new JsonResponse(['confirmed' => $traffic->confirm($request)], headers: ['Cache-Control' => 'private, no-store']);
    }
}
