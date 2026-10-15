<?php

namespace App\Traffic;

use App\Entity\TrafficEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пишет заходы на страницы сайта и скачивания установщика. Визит — открытие страницы без cookie
 * текущего визита (30 минут) или переход с другого сайта. Первый источник браузера хранится
 * в cookie на 90 дней: по нему относим скачивание, даже если человек пришёл из поиска вчера.
 */
final class TrafficRecorder
{
    public const VISIT_COOKIE = 'pv';
    public const FIRST_TOUCH_COOKIE = 'ft';
    private const VISIT_TTL = 1800;
    private const FIRST_TOUCH_TTL = 90 * 86400;

    /** Поисковые и прочие роботы, превью ссылок в мессенджерах, проверки доступности. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|vkshare|whatsapp|curl|wget|python|go-http|java\/|headless|lighthouse|uptime/i';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function recordVisit(Request $request, Response $response): void
    {
        if (!$this->isBrowser($request)) {
            return;
        }

        $referrer = $this->referrer($request);
        $utmSource = $this->utmSource($request);
        $source = TrafficSource::classify($referrer, $utmSource);
        $inVisit = $request->cookies->has(self::VISIT_COOKIE);
        $external = $source !== TrafficSource::INTERNAL && ($referrer !== null || $utmSource !== null);

        if (!$inVisit || $external) {
            if ($source === TrafficSource::INTERNAL) {
                // Cookie визита истекла, а человек ходит по сайту из открытой вкладки — это тот же визит.
                $source = TrafficSource::DIRECT;
                $referrer = null;
            }
            $this->persist(TrafficEvent::VISIT, $request, $source, $referrer, $request->getPathInfo(), $utmSource, $this->utmCampaign($request));

            if (!$request->cookies->has(self::FIRST_TOUCH_COOKIE)) {
                $response->headers->setCookie($this->cookie(self::FIRST_TOUCH_COOKIE, $this->encodeFirstTouch($source, $referrer, $request), self::FIRST_TOUCH_TTL));
            }
        }

        $response->headers->setCookie($this->cookie(self::VISIT_COOKIE, '1', self::VISIT_TTL));
    }

    /** Скачивание установщика из браузера. Лаунчер скачивает обновления своим HTTP-клиентом — их не считаем. */
    public function recordDownload(Request $request): void
    {
        if (!$this->isBrowser($request) || $request->headers->has('x-launcher-guid')) {
            return;
        }

        $first = $this->decodeFirstTouch($request->cookies->get(self::FIRST_TOUCH_COOKIE));
        if ($first !== null) {
            $this->persist(TrafficEvent::DOWNLOAD, $request, $first['s'], $first['r'], $first['l'], $first['us'], $first['uc']);

            return;
        }

        // Ссылку на скачивание открыли сразу, минуя страницы сайта (например, из поста или видео).
        $referrer = $this->referrer($request);
        $source = TrafficSource::classify($referrer, $this->utmSource($request));
        $this->persist(TrafficEvent::DOWNLOAD, $request, $source === TrafficSource::INTERNAL ? TrafficSource::UNKNOWN : $source, $referrer, $request->getPathInfo(), $this->utmSource($request), $this->utmCampaign($request));
    }

    private function persist(string $type, Request $request, string $source, ?string $referrer, string $landing, ?string $utmSource, ?string $utmCampaign): void
    {
        $this->em->persist(new TrafficEvent(
            $type,
            substr($source, 0, 32),
            $referrer,
            TrafficSource::host($referrer),
            substr($landing, 0, 512),
            $utmSource,
            $utmCampaign,
            (string) $request->getClientIp(),
        ));
        $this->em->flush();
    }

    private function isBrowser(Request $request): bool
    {
        $agent = (string) $request->headers->get('User-Agent');

        return str_contains($agent, 'Mozilla') && !preg_match(self::BOT_PATTERN, $agent);
    }

    private function referrer(Request $request): ?string
    {
        $referrer = trim((string) $request->headers->get('referer'));

        return $referrer !== '' ? substr($referrer, 0, 1024) : null;
    }

    /** utm_source или короткая метка ?ref=steam. */
    private function utmSource(Request $request): ?string
    {
        $value = trim((string) ($request->query->get('utm_source') ?: $request->query->get('ref')));

        return $value !== '' ? substr($value, 0, 100) : null;
    }

    private function utmCampaign(Request $request): ?string
    {
        $value = trim((string) $request->query->get('utm_campaign'));

        return $value !== '' ? substr($value, 0, 100) : null;
    }

    private function encodeFirstTouch(string $source, ?string $referrer, Request $request): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([
            's' => $source,
            'r' => $referrer !== null ? substr($referrer, 0, 300) : null,
            'l' => substr($request->getPathInfo(), 0, 200),
            'us' => $this->utmSource($request),
            'uc' => $this->utmCampaign($request),
        ])), '+/', '-_'), '=');
    }

    /** @return array{s: string, r: ?string, l: string, us: ?string, uc: ?string}|null */
    private function decodeFirstTouch(mixed $value): ?array
    {
        if (!is_string($value) || $value === '' || strlen($value) > 1500) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/')), true);
        if (!is_array($data) || !is_string($data['s'] ?? null)) {
            return null;
        }

        $text = static fn ($v, int $max) => is_string($v) && $v !== '' ? substr($v, 0, $max) : null;

        return [
            // Cookie правит сам браузер — ключ источника берём только из известного списка.
            's' => isset(TrafficSource::LABELS[$data['s']]) ? $data['s'] : TrafficSource::OTHER,
            'r' => $text($data['r'] ?? null, 1024),
            'l' => $text($data['l'] ?? null, 512) ?? '/',
            'us' => $text($data['us'] ?? null, 100),
            'uc' => $text($data['uc'] ?? null, 100),
        ];
    }

    private function cookie(string $name, string $value, int $ttl): Cookie
    {
        return Cookie::create($name, $value, time() + $ttl, '/', null, null, true, false, Cookie::SAMESITE_LAX);
    }
}
