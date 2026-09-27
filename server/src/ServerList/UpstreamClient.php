<?php

namespace App\ServerList;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Загрузка списков серверов из DZSA и BattleMetrics.
 * Ошибки не бросаются, а копятся в $errors — они уходят в ответ API для диагностики.
 */
final class UpstreamClient
{
    public const DZSA_URLS = [
        'https://dayzsalauncher.com/api/v2/launcher/servers/dayz',
        'https://dayzsalauncher.com/api/v2/launcher/listings/dayz',
    ];

    public const BATTLEMETRICS_URL = 'https://api.battlemetrics.com/servers';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(BATTLEMETRICS_TOKEN)%')]
        private readonly string $battlemetricsToken,
    ) {
    }

    public function isBattlemetricsEnabled(): bool
    {
        return trim($this->battlemetricsToken) !== '';
    }

    /**
     * @param list<array> $errors
     * @return list<array> сырые серверы DZSA
     */
    public function fetchDzsa(Deadline $deadline, array &$errors): array
    {
        $servers = [];
        foreach (self::DZSA_URLS as $url) {
            if ($deadline->isExceeded()) {
                $errors[] = ['source' => 'proxy', 'url' => $url, 'error' => 'Time budget exceeded before DZSA fetch completed'];
                break;
            }

            $json = $this->fetchJson($url, $deadline, $errors);
            foreach (self::extractList($json) as $raw) {
                if (is_array($raw)) {
                    $servers[] = $raw;
                }
            }
        }

        return $servers;
    }

    /**
     * @param list<array> $errors
     * @return array{items: list<array>, pagesFetched: int, hasMore: bool, truncated: bool}
     */
    public function fetchBattlemetrics(int $maxPages, Deadline $deadline, array &$errors): array
    {
        $items = [];
        $pagesFetched = 0;
        $truncated = false;
        $nextUrl = self::BATTLEMETRICS_URL . '?' . http_build_query([
            'filter[game]' => 'dayz',
            'filter[status]' => 'online',
            'page[size]' => '100',
            'sort' => '-players',
        ]);

        while ($pagesFetched < $maxPages && $nextUrl !== '') {
            if ($deadline->isExceeded()) {
                $truncated = true;
                $errors[] = ['source' => 'battlemetrics', 'status' => 206, 'error' => 'Time budget exceeded while fetching BattleMetrics pages', 'pagesFetched' => $pagesFetched];
                break;
            }

            $json = $this->fetchJson($nextUrl, $deadline, $errors, ['Authorization' => 'Bearer ' . trim($this->battlemetricsToken)]);
            if (!is_array($json)) {
                break;
            }

            foreach (self::extractList($json['data'] ?? null) as $raw) {
                if (is_array($raw)) {
                    $items[] = $raw;
                }
            }

            ++$pagesFetched;
            $nextUrl = is_string($json['links']['next'] ?? null) ? $json['links']['next'] : '';
        }

        $hasMore = $nextUrl !== '';
        if ($hasMore && $pagesFetched >= $maxPages) {
            $truncated = true;
            $errors[] = ['source' => 'battlemetrics', 'status' => 206, 'error' => 'BattleMetrics page limit reached before all servers were fetched', 'pagesFetched' => $pagesFetched, 'maxPages' => $maxPages];
        }

        return ['items' => $items, 'pagesFetched' => $pagesFetched, 'hasMore' => $hasMore, 'truncated' => $truncated];
    }

    private function fetchJson(string $url, Deadline $deadline, array &$errors, array $headers = []): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'DZSALauncher-Proxy/3.2'] + $headers,
                'timeout' => min(6, $deadline->secondsLeft()),
                'max_duration' => $deadline->secondsLeft(),
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $errors[] = ['url' => $url, 'status' => $status, 'error' => self::apiErrorDetail($response->getContent(false)) ?? 'HTTP request failed'];

                return null;
            }

            $json = json_decode($response->getContent(), true);
            if (!is_array($json)) {
                $errors[] = ['url' => $url, 'status' => $status, 'error' => 'Invalid JSON'];

                return null;
            }

            return $json;
        } catch (ExceptionInterface $e) {
            $errors[] = ['url' => $url, 'status' => 0, 'error' => $e->getMessage()];

            return null;
        }
    }

    /** Текст ошибки из ответа API: { "errors": [{ "detail": "..." }] } или { "error": "..." }. */
    private static function apiErrorDetail(string $body): ?string
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        $detail = $json['errors'][0]['detail'] ?? $json['errors'][0]['title'] ?? $json['error'] ?? $json['message'] ?? null;

        return is_string($detail) && $detail !== '' ? $detail : null;
    }

    /** Список серверов: либо корневой массив, либо поле servers/result/data/items. */
    private static function extractList(mixed $json): array
    {
        if (!is_array($json)) {
            return [];
        }

        if (array_is_list($json)) {
            return $json;
        }

        foreach (['servers', 'result', 'data', 'items'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return $json[$key];
            }
        }

        return [];
    }
}
