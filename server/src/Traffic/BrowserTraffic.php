<?php

namespace App\Traffic;

use App\Entity\TrafficEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BrowserTraffic
{
    public const VISITOR_COOKIE = 'pt_visitor';
    public const VISIT_TTL = 1800;

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|meta-external|vkshare|whatsapp|curl|wget|python|go-http|java\/|headless|lighthouse|uptime|googleother|google-inspectiontool|google-read-aloud|mediapartners|adsense|scanner|checker|scraper|httpclient|phantomjs|selenium|puppeteer|playwright/i';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function isBrowser(Request $request): bool
    {
        $agent = $request->headers->get('User-Agent', '');
        $purpose = $request->headers->get('Sec-Purpose', '') . $request->headers->get('Purpose', '');

        return str_contains($agent, 'Mozilla') && !preg_match(self::BOT_PATTERN, $agent)
            && !str_contains(strtolower($purpose), 'prefetch') && !$request->headers->has('x-launcher-guid');
    }

    public function visitorId(Request $request): string
    {
        $cookie = $request->cookies->get(self::VISITOR_COOKIE, '');
        $parts = explode('.', $cookie);
        if (count($parts) === 2 && preg_match('/^[a-f0-9]{32}$/D', $parts[0])
            && hash_equals($this->sign('visitor:' . $parts[0]), $parts[1])) {
            return $parts[0];
        }

        return bin2hex(random_bytes(16));
    }

    public function currentVisit(Request $request): ?array
    {
        if (!$this->isBrowser($request)) {
            return null;
        }
        $parts = explode('.', $request->cookies->get(TrafficRecorder::VISIT_COOKIE, ''));
        if (count($parts) !== 2 || !hash_equals($this->sign('visit:' . $parts[0] . ':' . $request->headers->get('User-Agent', '')), $parts[1])) {
            return null;
        }
        $data = explode(':', $parts[0]);
        $now = $this->clock->now()->getTimestamp();
        if (count($data) !== 3 || !ctype_digit($data[0]) || !ctype_digit($data[1]) || !preg_match('/^[a-f0-9]{32}$/D', $data[2])
            || (int) $data[1] > $now || (int) $data[1] < $now - self::VISIT_TTL || $data[2] !== $this->visitorId($request)) {
            return null;
        }

        return $this->db->fetchAssociative('SELECT * FROM traffic_event WHERE id = ? AND type = ? AND visitor_id = ? AND verification IN (?, ?)',
            [(int) $data[0], TrafficEvent::VISIT, $data[2], TrafficEvent::PENDING, TrafficEvent::CONFIRMED]) ?: null;
    }

    public function setCookies(Request $request, Response $response, int $visitId, string $visitorId): void
    {
        $body = $visitId . ':' . $this->clock->now()->getTimestamp() . ':' . $visitorId;
        $response->headers->setCookie($this->cookie($request, TrafficRecorder::VISIT_COOKIE,
            $body . '.' . $this->sign('visit:' . $body . ':' . $request->headers->get('User-Agent', '')), self::VISIT_TTL));
        $response->headers->setCookie($this->cookie($request, self::VISITOR_COOKIE,
            $visitorId . '.' . $this->sign('visitor:' . $visitorId), 180 * 86400));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
    }

    public function confirm(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (($origin !== null && $origin !== $request->getSchemeAndHttpHost())
            || $request->headers->get('Sec-Fetch-Site') === 'cross-site'
            || $request->headers->get('X-Traffic-Event') !== 'engaged') {
            return false;
        }
        $visit = $this->currentVisit($request);
        if ($visit === null || $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($visit['created_at']))->getTimestamp() < 5) {
            return false;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->executeStatement('UPDATE traffic_event SET verification = ?, confirmed_at = ? WHERE (id = ? OR visit_id = ?) AND verification = ?',
            [TrafficEvent::CONFIRMED, $now, $visit['id'], $visit['id'], TrafficEvent::PENDING]);

        return true;
    }

    private function sign(string $value): string
    {
        return hash_hmac('sha256', $value, $this->secret);
    }

    private function cookie(Request $request, string $name, string $value, int $ttl): Cookie
    {
        return Cookie::create($name, $value, $this->clock->now()->getTimestamp() + $ttl)
            ->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite('lax');
    }
}
